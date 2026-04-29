<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\Client\Api\ClientConfigInterface;
use Byte8\Client\Api\Data\OutboxEventInterface;
use Byte8\Client\Api\EntitySyncStateRepositoryInterface;
use Byte8\Client\Api\OutboxEventRepositoryInterface;
use Byte8\Client\Exception\BadRequestException;
use Byte8\Client\Exception\NotConnectedException;
use Byte8\Client\Exception\TransientPublishException;
use Byte8\Client\Model\Jwt\Signer;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

class ByteClient implements ByteClientInterface
{
    private const WEBHOOK_PATH = '/webhooks/magento/%s/%s';
    private const STATUS_PATH = '/v1/status';
    private const HEALTH_PATH = '/v1/tile/health';
    private const DISCONNECT_PATH = '/v1/tenant/disconnect';
    private const HEALTH_CACHE_KEY_PREFIX = 'byte8_tile_health_';
    private const HEALTH_CACHE_TTL = 30;
    private const CONNECT_TIMEOUT = 5;
    private const READ_TIMEOUT = 15;

    public function __construct(
        private readonly ClientConfigInterface $config,
        private readonly Signer $jwtSigner,
        private readonly CurlFactory $curlFactory,
        private readonly OutboxEventRepositoryInterface $outboxRepository,
        private readonly OutboxEventFactory $outboxFactory,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly EntitySyncStateRepositoryInterface $entitySyncStateRepository
    ) {
    }

    public function publishEvent(string $eventName, array $payload, ?string $idempotencyKey = null): string
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $idempotencyKey ??= $this->deriveIdempotencyKey($eventName, $body);

        try {
            $response = $this->post($this->webhookUrl($eventName), $body, $idempotencyKey);
            $decoded = $this->decodeBody($response['body']);
            return (string) ($decoded['sync_run_id'] ?? '');
        } catch (TransientPublishException $e) {
            $this->enqueue($eventName, $body, $idempotencyKey, $e->getMessage());
            return '';
        }
    }

    public function enqueueEvent(
        string $eventName,
        array $payload,
        ?string $idempotencyKey = null,
        ?string $providerForMirror = null
    ): void {
        // Validate base_url / tenant eagerly so misconfiguration surfaces
        // as a NotConnectedException here rather than silently dropping
        // the row deep inside OutboxDrain.
        $this->webhookUrl($eventName);

        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $idempotencyKey ??= $this->deriveIdempotencyKey($eventName, $body);

        // Deferred-first: no HTTP in the save transaction. Cron drains
        // within 60s. `last_error` is empty because the row wasn't
        // produced by a failure — just marks "not yet attempted".
        $this->enqueue($eventName, $body, $idempotencyKey, '');

        // PR7 write-through. Drives the "Sage Status" chip on the
        // admin grids immediately after enqueue (no wait for the cron
        // drain or the ledger callback). Best-effort: a mirror-write
        // failure is logged but does not abort the outbox enqueue —
        // the outbox row is the source of truth and the ledger
        // callback will eventually populate the terminal status.
        if ($providerForMirror !== null && $providerForMirror !== '') {
            $this->writeMirrorPending($eventName, $payload, $providerForMirror);
        }
    }

    public function fetchStatus(string $entityType, string $entityId): array
    {
        $query = http_build_query([
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
        ]);
        $response = $this->get($this->baseUrl() . self::STATUS_PATH . '?' . $query);
        return $this->decodeBody($response['body']);
    }

    public function disconnect(): bool
    {
        if (!$this->config->isConnected()) {
            return true; // nothing to tell ledger about
        }
        try {
            $this->post($this->baseUrl() . self::DISCONNECT_PATH, '{}', 'disconnect:' . sha1($this->config->getTenantId() ?? ''));
        } catch (BadRequestException | NotConnectedException | TransientPublishException $e) {
            $this->logger->warning('Byte8: disconnect call to ledger failed (continuing anyway): ' . $e->getMessage());
            return false;
        }
        return true;
    }

    public function fetchHealth(): array
    {
        $tenantId = (string) $this->config->getTenantId();
        if ($tenantId === '' || !$this->config->isConnected()) {
            // Pre-Connect: no point calling ledger — tile degrades to "not connected".
            return [];
        }

        $cacheKey = self::HEALTH_CACHE_KEY_PREFIX . sha1($tenantId);
        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        try {
            $response = $this->get($this->baseUrl() . self::HEALTH_PATH);
            $decoded = $this->decodeBody($response['body']);
        } catch (BadRequestException | NotConnectedException | TransientPublishException $e) {
            // Tile must never propagate errors to the dashboard render.
            $this->logger->warning('Byte8: fetchHealth failed: ' . $e->getMessage());
            return [];
        }

        $this->cache->save(
            (string) json_encode($decoded, JSON_UNESCAPED_SLASHES),
            $cacheKey,
            [ByteClientInterface::HEALTH_CACHE_TAG],
            self::HEALTH_CACHE_TTL
        );

        return $decoded;
    }

    /**
     * Internal POST used by both publishEvent and the cron drain.
     *
     * @internal — module-private, not part of the public interface.
     *
     * @return array{status:int, body:string}
     * @throws BadRequestException
     * @throws NotConnectedException
     * @throws TransientPublishException
     */
    public function postRaw(string $url, string $body, string $idempotencyKey): array
    {
        return $this->post($url, $body, $idempotencyKey);
    }

    /**
     * @return array{status:int, body:string}
     * @throws BadRequestException
     * @throws NotConnectedException
     * @throws TransientPublishException
     */
    private function post(string $url, string $body, string $idempotencyKey): array
    {
        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        $curl->setOption(CURLOPT_TIMEOUT, self::READ_TIMEOUT);
        $curl->addHeader('Authorization', 'Bearer ' . $this->jwtSigner->mint());
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Idempotency-Key', $idempotencyKey);

        try {
            $curl->post($url, $body);
        } catch (\Throwable $e) {
            throw new TransientPublishException(
                __('Network error publishing to Byte8 (%1): %2', $url, $e->getMessage()),
                $e
            );
        }

        return $this->dispatchResponse($curl, $url);
    }

    /**
     * @return array{status:int, body:string}
     * @throws BadRequestException
     * @throws NotConnectedException
     * @throws TransientPublishException
     */
    private function get(string $url): array
    {
        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        $curl->setOption(CURLOPT_TIMEOUT, self::READ_TIMEOUT);
        $curl->addHeader('Authorization', 'Bearer ' . $this->jwtSigner->mint());
        $curl->addHeader('Accept', 'application/json');

        try {
            $curl->get($url);
        } catch (\Throwable $e) {
            throw new TransientPublishException(
                __('Network error fetching from Byte8 (%1): %2', $url, $e->getMessage()),
                $e
            );
        }

        return $this->dispatchResponse($curl, $url);
    }

    /**
     * @return array{status:int, body:string}
     * @throws BadRequestException
     * @throws TransientPublishException
     */
    private function dispatchResponse(\Magento\Framework\HTTP\Client\Curl $curl, string $url): array
    {
        $status = (int) $curl->getStatus();
        $body = (string) $curl->getBody();

        if ($status >= 200 && $status < 300) {
            return ['status' => $status, 'body' => $body];
        }

        if ($status >= 400 && $status < 500) {
            throw new BadRequestException(
                __('Byte8 rejected request to %1 with HTTP %2.', $url, $status),
                $status,
                $body
            );
        }

        // 5xx or other — treat as transient
        throw new TransientPublishException(
            __('Byte8 returned HTTP %1 from %2; will retry.', $status, $url)
        );
    }

    private function enqueue(string $eventName, string $body, string $idempotencyKey, string $lastError): void
    {
        try {
            if ($existing = $this->outboxRepository->getByIdempotencyKey($idempotencyKey)) {
                // Observer re-fire before drain picked it up — nothing new to do.
                return;
            }
            /** @var OutboxEventInterface $event */
            $event = $this->outboxFactory->create();
            $event->setIdempotencyKey($idempotencyKey)
                ->setEventName($eventName)
                ->setPayload($body)
                ->setAttempts(0)
                ->setNextAttemptAt(null)
                ->setLastError($lastError);
            $this->outboxRepository->save($event);
        } catch (CouldNotSaveException $e) {
            // Don't escalate to the observer — the event is already lost
            // from the SaaS side's POV. Log so ops see it in the Byte8
            // log stream.
            $this->logger->error(
                sprintf('Byte8: failed to enqueue event %s in outbox: %s', $eventName, $e->getMessage())
            );
        }
    }

    private function deriveIdempotencyKey(string $eventName, string $canonicalBody): string
    {
        return $eventName . ':' . sha1($canonicalBody);
    }

    /**
     * PR7 write-through. Best-effort UPSERT of a `pending` row in
     * `byte8_entity_sync_state`. Skipped silently when:
     *
     * - The event name doesn't have a recognised entity prefix
     *   (`invoice.` / `creditmemo.` / `customer.` / `product.`). Future
     *   event types that don't represent a user-facing entity (e.g.
     *   internal heartbeats) just don't trigger a chip.
     * - `magento_entity_id` is missing or non-positive. Mirrors
     *   `enqueueEvent`'s payload contract — observers always include it,
     *   but a malformed call shouldn't crash the enqueue path.
     *
     * Failures are logged at warn (callback row will eventually populate
     * the row anyway) but never propagate — the outbox enqueue must
     * remain the contract.
     */
    private function writeMirrorPending(string $eventName, array $payload, string $provider): void
    {
        $entityType = $this->entityTypeFromEventName($eventName);
        if ($entityType === null) {
            return;
        }
        $magentoId = $payload['magento_entity_id'] ?? null;
        if (!is_int($magentoId) && !ctype_digit((string) $magentoId)) {
            return;
        }
        $magentoId = (int) $magentoId;
        if ($magentoId <= 0) {
            return;
        }

        try {
            $this->entitySyncStateRepository->markPending($entityType, $magentoId, $provider);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'Byte8: write-through mirror markPending failed for %s/%s: %s',
                $entityType,
                $magentoId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Map `event_name` (e.g. `invoice.created`) to the entity-type code
     * the ledger-side `magento_entity_for_push` uses. Return null when
     * the event doesn't represent a user-facing Magento entity (so no
     * mirror chip is rendered).
     */
    private function entityTypeFromEventName(string $eventName): ?string
    {
        $prefix = strtok($eventName, '.');
        return match ($prefix) {
            'invoice'    => 'invoice',
            'creditmemo' => 'creditmemo',
            'customer'   => 'customer',
            'product'    => 'product',
            default      => null,
        };
    }

    private function decodeBody(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function webhookUrl(string $eventName): string
    {
        $tenantId = $this->config->getTenantId();
        if ($tenantId === null) {
            throw new NotConnectedException(__('Byte8 client tenant_id is not configured.'));
        }
        return $this->baseUrl() . sprintf(self::WEBHOOK_PATH, rawurlencode($tenantId), rawurlencode($eventName));
    }

    private function baseUrl(): string
    {
        $base = $this->config->getBaseUrl();
        if ($base === '') {
            throw new NotConnectedException(__('Byte8 client base_url is not configured.'));
        }
        return $base;
    }
}
