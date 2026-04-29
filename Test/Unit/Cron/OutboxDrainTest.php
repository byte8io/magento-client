<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Test\Unit\Cron;

use Byte8\Client\Api\ClientConfigInterface;
use Byte8\Client\Api\Data\OutboxEventInterface;
use Byte8\Client\Api\OutboxEventRepositoryInterface;
use Byte8\Client\Cron\OutboxDrain;
use Byte8\Client\Exception\BadRequestException;
use Byte8\Client\Exception\TransientPublishException;
use Byte8\Client\Model\ByteClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OutboxDrainTest extends TestCase
{
    /** @var OutboxEventRepositoryInterface&MockObject */
    private OutboxEventRepositoryInterface $repository;

    /** @var ByteClient&MockObject */
    private ByteClient $byteClient;

    /** @var ClientConfigInterface&MockObject */
    private ClientConfigInterface $config;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private OutboxDrain $drain;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(OutboxEventRepositoryInterface::class);
        $this->byteClient = $this->createMock(ByteClient::class);
        $this->config = $this->createMock(ClientConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('getBaseUrl')->willReturn('https://ledger.byte8.io');
        $this->config->method('getTenantId')->willReturn('tenant-1');

        $this->drain = new OutboxDrain(
            $this->repository,
            $this->byteClient,
            $this->config,
            $this->logger
        );
    }

    public function testExecuteIsNoOpWhenNotConnected(): void
    {
        $this->config->method('isConnected')->willReturn(false);
        $this->repository->expects(self::never())->method('getDueBatch');

        $this->drain->execute();
    }

    public function testSuccessfulPostDeletesRow(): void
    {
        $this->config->method('isConnected')->willReturn(true);
        $event = $this->makeEvent(id: 1, eventName: 'invoice.paid', attempts: 0);
        $this->repository->method('getDueBatch')->willReturn([$event]);

        $this->byteClient->expects(self::once())
            ->method('postRaw')
            ->with(
                'https://ledger.byte8.io/webhooks/magento/tenant-1/invoice.paid',
                self::anything(),
                self::anything()
            );
        $this->repository->expects(self::once())->method('delete')->with($event);
        $this->repository->expects(self::never())->method('save');

        $this->drain->execute();
    }

    public function testBadRequestDropsRow(): void
    {
        $this->config->method('isConnected')->willReturn(true);
        $event = $this->makeEvent(id: 2, eventName: 'invoice.paid', attempts: 1);
        $this->repository->method('getDueBatch')->willReturn([$event]);

        $this->byteClient->method('postRaw')->willThrowException(
            new BadRequestException(__('rejected'), 422, 'validation failed')
        );

        $this->repository->expects(self::once())->method('delete')->with($event);
        $this->repository->expects(self::never())->method('save');
        $this->logger->expects(self::once())->method('error');

        $this->drain->execute();
    }

    public function testTransientFailureSchedulesFirstBackoffTier(): void
    {
        $this->config->method('isConnected')->willReturn(true);
        $event = $this->makeEvent(id: 3, eventName: 'invoice.paid', attempts: 0);
        $this->repository->method('getDueBatch')->willReturn([$event]);

        $this->byteClient->method('postRaw')->willThrowException(
            new TransientPublishException(__('ledger 503'))
        );

        $savedAttempts = null;
        $savedNextAt = null;
        $this->repository->expects(self::once())
            ->method('save')
            ->willReturnCallback(
                function (OutboxEventInterface $saved) use (&$savedAttempts, &$savedNextAt): OutboxEventInterface {
                    $savedAttempts = $saved->getAttempts();
                    $savedNextAt = $saved->getNextAttemptAt();
                    return $saved;
                }
            );
        $this->repository->expects(self::never())->method('delete');

        $this->drain->execute();

        self::assertSame(1, $savedAttempts);
        self::assertNotNull($savedNextAt);
        // First backoff tier is 60s; allow ±2s jitter for test execution time.
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $next = new \DateTimeImmutable($savedNextAt, new \DateTimeZone('UTC'));
        $delta = $next->getTimestamp() - $now->getTimestamp();
        self::assertGreaterThanOrEqual(58, $delta, 'first tier ~= 60s');
        self::assertLessThanOrEqual(62, $delta, 'first tier ~= 60s');
    }

    public function testExpiredRowGetsDroppedRegardlessOfAttempts(): void
    {
        $this->config->method('isConnected')->willReturn(true);
        // Use -48h to clear the 24h TTL even under the LA-timezone bootstrap
        // that Magento's unit tests run under (strtotime() on a UTC-formatted
        // string is reinterpreted in the ambient tz, costing ~8h).
        $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-48 hours')
            ->format('Y-m-d H:i:s');
        $event = $this->makeEvent(id: 4, eventName: 'invoice.paid', attempts: 2, createdAt: $createdAt);
        $this->repository->method('getDueBatch')->willReturn([$event]);

        $this->byteClient->expects(self::never())->method('postRaw');
        $this->repository->expects(self::once())->method('delete')->with($event);
        $this->logger->expects(self::once())->method('error');

        $this->drain->execute();
    }

    public function testOneBadRowDoesNotHaltBatch(): void
    {
        $this->config->method('isConnected')->willReturn(true);
        $bad = $this->makeEvent(id: 5, eventName: 'invoice.paid');
        $good = $this->makeEvent(id: 6, eventName: 'customer.upserted');
        $this->repository->method('getDueBatch')->willReturn([$bad, $good]);

        $call = 0;
        $this->byteClient->method('postRaw')->willReturnCallback(
            function () use (&$call): array {
                $call++;
                if ($call === 1) {
                    throw new \RuntimeException('boom — cache layer crashed');
                }
                return ['status' => 202, 'body' => '{}'];
            }
        );

        $this->repository->expects(self::once())->method('delete')->with($good);
        $this->logger->expects(self::atLeastOnce())->method('error');

        $this->drain->execute();
    }

    /**
     * Interface-mocked event row. Kept as a live value object via state
     * capture so attempts / next_attempt_at / last_error mutations that
     * OutboxDrain performs in-place are observable after save() is called.
     */
    private function makeEvent(
        int $id,
        string $eventName,
        int $attempts = 0,
        ?string $createdAt = null
    ): OutboxEventInterface {
        $state = [
            'id' => $id,
            'idempotency_key' => $eventName . ':' . $id,
            'event_name' => $eventName,
            'payload' => '{"magento_entity_id":' . $id . '}',
            'attempts' => $attempts,
            'next_attempt_at' => null,
            'last_error' => null,
            'created_at' => $createdAt
                ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];

        // Short-arrow fn() captures by value — we need by-reference bindings
        // so setAttempts() / setNextAttemptAt() mutations are visible to
        // subsequent getAttempts() / getNextAttemptAt() reads.
        $event = $this->createMock(OutboxEventInterface::class);
        $event->method('getEntityId')->willReturnCallback(
            function () use (&$state) { return $state['id']; }
        );
        $event->method('getIdempotencyKey')->willReturnCallback(
            function () use (&$state) { return $state['idempotency_key']; }
        );
        $event->method('getEventName')->willReturnCallback(
            function () use (&$state) { return $state['event_name']; }
        );
        $event->method('getPayload')->willReturnCallback(
            function () use (&$state) { return $state['payload']; }
        );
        $event->method('getAttempts')->willReturnCallback(
            function () use (&$state) { return $state['attempts']; }
        );
        $event->method('getNextAttemptAt')->willReturnCallback(
            function () use (&$state) { return $state['next_attempt_at']; }
        );
        $event->method('getLastError')->willReturnCallback(
            function () use (&$state) { return $state['last_error']; }
        );
        $event->method('getCreatedAt')->willReturnCallback(
            function () use (&$state) { return $state['created_at']; }
        );
        $event->method('setAttempts')->willReturnCallback(
            function (int $v) use (&$state, $event) {
                $state['attempts'] = $v;
                return $event;
            }
        );
        $event->method('setNextAttemptAt')->willReturnCallback(
            function (?string $v) use (&$state, $event) {
                $state['next_attempt_at'] = $v;
                return $event;
            }
        );
        $event->method('setLastError')->willReturnCallback(
            function (?string $v) use (&$state, $event) {
                $state['last_error'] = $v;
                return $event;
            }
        );
        return $event;
    }
}
