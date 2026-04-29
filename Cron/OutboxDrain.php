<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Cron;

use Byte8\Client\Api\ClientConfigInterface;
use Byte8\Client\Api\Data\OutboxEventInterface;
use Byte8\Client\Api\OutboxEventRepositoryInterface;
use Byte8\Client\Exception\BadRequestException;
use Byte8\Client\Exception\NotConnectedException;
use Byte8\Client\Exception\TransientPublishException;
use Byte8\Client\Model\ByteClient;
use Psr\Log\LoggerInterface;

/**
 * Drains byte8_event_outbox — the primary delivery queue for every
 * outbound Byte8 event. Runs every minute, up to BATCH_SIZE rows per
 * tick.
 *
 * Failure classification (critical — do not dilute):
 *
 *   - **Transient** (5xx, network, timeout) → exponential backoff,
 *     capped at MAX_TRANSIENT_ATTEMPTS. Beyond the cap the row is
 *     dead-lettered rather than silently dropped; an operator must
 *     inspect and requeue (`bin/magento byte8:sage:outbox:requeue`).
 *
 *   - **Deterministic** (4xx — 400, 401, 403, 404, 422) → dead-lettered
 *     on the first failure with full response body preserved. Retrying
 *     the same payload against the same SaaS will keep failing; the
 *     merchant needs to see the error and fix the underlying mapping
 *     (e.g. missing Sage tax code, bad contact reference). Never
 *     deleted by the cron — loss of a row is worse than a noisy DB.
 *
 *   - **Success** (2xx) → status=succeeded, preserved for audit until
 *     a future GC policy lands. Auditors need the trail.
 *
 * Backoff schedule (seconds from last failure), length = MAX_TRANSIENT_ATTEMPTS:
 *     attempt 1 →     60s   (1 min)
 *     attempt 2 →    300s   (5 min)
 *     attempt 3 →   1 800s  (30 min)
 *     attempt 4 →   7 200s  (2 h)
 *     attempt 5 →  21 600s  (6 h)
 *     attempt 6 →  43 200s  (12 h)
 *     attempt 7+ →  86 400s (24 h cap)
 *     attempt 10 → dead-letter (transient_exhausted)
 */
class OutboxDrain
{
    private const BATCH_SIZE = 50;

    /**
     * Transient retry budget. Generous by design — a 7-day platform
     * outage should not silently drop a merchant's invoices. The
     * backoff ladder caps at 24h per attempt so 10 attempts ≈ 7 days
     * worst case.
     */
    private const MAX_TRANSIENT_ATTEMPTS = 10;

    /** @var int[] seconds-since-last-failure per attempt index (1-based) */
    private const BACKOFF_SECONDS = [60, 300, 1800, 7200, 21600, 43200, 86400];

    private const DEAD_LETTER_REASON_TRANSIENT_EXHAUSTED =
        'transient_exhausted: retries capped at MAX_TRANSIENT_ATTEMPTS without success';

    public function __construct(
        private readonly OutboxEventRepositoryInterface $outboxRepository,
        private readonly ByteClient $byteClient,
        private readonly ClientConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isConnected()) {
            // Pre-Connect: leave pending rows alone. Connect may wire
            // credentials any moment — we'd rather retry stored events
            // once the wiring is in place than burn the retry budget
            // without having a real target.
            return;
        }

        $batch = $this->outboxRepository->getDueBatch(self::BATCH_SIZE);
        foreach ($batch as $event) {
            try {
                $this->processRow($event);
            } catch (\Throwable $e) {
                // Don't let a single bad row halt the batch. Anything
                // reaching here is a genuinely unexpected exception —
                // repo save failure, JSON corruption, etc.
                $this->logger->error(sprintf(
                    'Byte8 outbox drain: row %s crashed: %s',
                    $event->getEntityId(),
                    $e->getMessage()
                ));
            }
        }
    }

    private function processRow(OutboxEventInterface $event): void
    {
        $url = $this->webhookUrl($event->getEventName());
        $payload = (string) $event->getPayload();
        $idempotencyKey = (string) $event->getIdempotencyKey();
        $now = $this->nowUtc();

        try {
            $response = $this->byteClient->postRaw($url, $payload, $idempotencyKey);
            $this->markSucceeded($event, (int) ($response['status'] ?? 200), $now);
        } catch (BadRequestException $e) {
            // 4xx — deterministic failure. Dead-letter immediately so
            // the merchant sees the error in the admin tile and can
            // fix the underlying mapping. DO NOT delete, DO NOT retry.
            $this->markDeadLettered(
                $event,
                $e->getHttpStatus(),
                $this->formatDeterministicError($e),
                $now
            );
        } catch (TransientPublishException $e) {
            $this->handleTransient($event, $e->getMessage(), $now);
        } catch (NotConnectedException $e) {
            // Credentials cleared mid-run; leave the row pending so the
            // next tick retries once config is stable.
            $this->logger->warning(
                'Byte8 outbox drain: client not connected, deferring batch: ' . $e->getMessage()
            );
        }
    }

    private function handleTransient(OutboxEventInterface $event, string $error, string $now): void
    {
        $attempts = $event->getAttempts() + 1;
        if ($attempts >= self::MAX_TRANSIENT_ATTEMPTS) {
            $this->markDeadLettered(
                $event,
                null,
                self::DEAD_LETTER_REASON_TRANSIENT_EXHAUSTED . ' — last error: ' . $error,
                $now
            );
            return;
        }

        $delay = self::BACKOFF_SECONDS[min($attempts, count(self::BACKOFF_SECONDS)) - 1];
        $nextAttemptAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
            ->modify('+' . $delay . ' seconds')
            ->format('Y-m-d H:i:s');

        $event->setStatus(OutboxEventInterface::STATUS_PENDING)
            ->setAttempts($attempts)
            ->setLastStatusCode(null)
            ->setLastAttemptAt($now)
            ->setNextAttemptAt($nextAttemptAt)
            ->setLastError($error);
        $this->outboxRepository->save($event);
    }

    private function markSucceeded(OutboxEventInterface $event, int $statusCode, string $now): void
    {
        $event->setStatus(OutboxEventInterface::STATUS_SUCCEEDED)
            ->setAttempts($event->getAttempts() + 1)
            ->setLastStatusCode($statusCode)
            ->setLastAttemptAt($now)
            ->setNextAttemptAt(null)
            ->setLastError(null);
        $this->outboxRepository->save($event);
    }

    private function markDeadLettered(
        OutboxEventInterface $event,
        ?int $statusCode,
        string $errorMessage,
        string $now
    ): void {
        $event->setStatus(OutboxEventInterface::STATUS_DEAD_LETTERED)
            ->setAttempts($event->getAttempts() + 1)
            ->setLastStatusCode($statusCode)
            ->setLastAttemptAt($now)
            ->setNextAttemptAt(null)
            ->setLastError($errorMessage);
        $this->outboxRepository->save($event);

        $this->logger->error(sprintf(
            'Byte8 outbox drain: dead-lettered event id=%s event=%s status=%s error=%s — requires operator review',
            $event->getEntityId(),
            $event->getEventName(),
            $statusCode === null ? 'transient_exhausted' : (string) $statusCode,
            $this->trimForLog($errorMessage)
        ));
    }

    private function formatDeterministicError(BadRequestException $e): string
    {
        $body = $e->getResponseBody();
        // Preserve enough of the response body for the merchant to fix
        // the underlying issue without bloating the row beyond reason.
        // text column handles mediumtext-worth of body; cap anyway.
        $bodyTruncated = strlen($body) > 8192 ? substr($body, 0, 8192) . '…[truncated]' : $body;
        return sprintf('HTTP %d: %s', $e->getHttpStatus(), $bodyTruncated);
    }

    private function trimForLog(string $msg): string
    {
        return strlen($msg) > 500 ? substr($msg, 0, 500) . '…' : $msg;
    }

    private function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function webhookUrl(string $eventName): string
    {
        return rtrim($this->config->getBaseUrl(), '/')
            . '/webhooks/magento/'
            . rawurlencode((string) $this->config->getTenantId())
            . '/'
            . rawurlencode($eventName);
    }
}
