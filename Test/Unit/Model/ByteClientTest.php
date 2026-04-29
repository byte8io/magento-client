<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Test\Unit\Model;

use Byte8\Client\Api\ClientConfigInterface;
use Byte8\Client\Api\Data\OutboxEventInterface;
use Byte8\Client\Api\EntitySyncStateRepositoryInterface;
use Byte8\Client\Api\OutboxEventRepositoryInterface;
use Byte8\Client\Exception\BadRequestException;
use Byte8\Client\Model\ByteClient;
use Byte8\Client\Model\Jwt\Signer;
use Byte8\Client\Model\OutboxEventFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ByteClientTest extends TestCase
{
    /** @var ClientConfigInterface&MockObject */
    private ClientConfigInterface $config;

    /** @var Signer&MockObject */
    private Signer $signer;

    /** @var CurlFactory&MockObject */
    private CurlFactory $curlFactory;

    /** @var Curl&MockObject */
    private Curl $curl;

    /** @var OutboxEventRepositoryInterface&MockObject */
    private OutboxEventRepositoryInterface $outbox;

    /** @var OutboxEventFactory&MockObject */
    private OutboxEventFactory $outboxFactory;

    /** @var CacheInterface&MockObject */
    private CacheInterface $cache;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var EntitySyncStateRepositoryInterface&MockObject */
    private EntitySyncStateRepositoryInterface $entitySyncState;

    private ByteClient $client;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ClientConfigInterface::class);
        $this->config->method('getBaseUrl')->willReturn('https://ledger.byte8.io');
        $this->config->method('getTenantId')->willReturn('tenant-1');
        $this->config->method('isConnected')->willReturn(true);

        $this->signer = $this->createMock(Signer::class);
        $this->signer->method('mint')->willReturn('fake.jwt.token');

        $this->curl = $this->createMock(Curl::class);
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->curlFactory->method('create')->willReturn($this->curl);

        $this->outbox = $this->createMock(OutboxEventRepositoryInterface::class);
        $this->outboxFactory = $this->createMock(OutboxEventFactory::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->entitySyncState = $this->createMock(EntitySyncStateRepositoryInterface::class);

        $this->client = new ByteClient(
            $this->config,
            $this->signer,
            $this->curlFactory,
            $this->outbox,
            $this->outboxFactory,
            $this->cache,
            $this->logger,
            $this->entitySyncState
        );
    }

    public function testPublishEventReturnsSyncRunIdOn2xx(): void
    {
        $this->curl->expects(self::once())->method('post')->with(
            'https://ledger.byte8.io/webhooks/magento/tenant-1/invoice.paid',
            self::stringContains('"magento_entity_id":99')
        );
        $this->curl->method('getStatus')->willReturn(202);
        $this->curl->method('getBody')->willReturn('{"sync_run_id":"run-abc"}');
        $this->outbox->expects(self::never())->method('save');

        $result = $this->client->publishEvent('invoice.paid', ['magento_entity_id' => 99]);
        self::assertSame('run-abc', $result);
    }

    public function testPublishEventThrowsBadRequestOn4xx(): void
    {
        $this->curl->method('getStatus')->willReturn(422);
        $this->curl->method('getBody')->willReturn('{"error":"validation"}');
        $this->outbox->expects(self::never())->method('save');

        $this->expectException(BadRequestException::class);
        $this->client->publishEvent('invoice.paid', ['magento_entity_id' => 1]);
    }

    public function testPublishEventEnqueuesOn5xxAndReturnsEmpty(): void
    {
        $this->curl->method('getStatus')->willReturn(503);
        $this->curl->method('getBody')->willReturn('service unavailable');

        $this->outbox->method('getByIdempotencyKey')->willReturn(null);
        $savedEvent = $this->createMock(OutboxEventInterface::class);
        $savedEvent->method('setIdempotencyKey')->willReturnSelf();
        $savedEvent->method('setEventName')->willReturnSelf();
        $savedEvent->method('setPayload')->willReturnSelf();
        $savedEvent->method('setAttempts')->willReturnSelf();
        $savedEvent->method('setNextAttemptAt')->willReturnSelf();
        $savedEvent->method('setLastError')->willReturnSelf();
        $this->outboxFactory->method('create')->willReturn($savedEvent);

        $this->outbox->expects(self::once())->method('save')->with($savedEvent);

        $result = $this->client->publishEvent('invoice.paid', ['magento_entity_id' => 1]);
        self::assertSame('', $result, 'Transient failure returns empty sync_run_id');
    }

    public function testPublishEventEnqueuesOnCurlException(): void
    {
        $this->curl->method('post')->willThrowException(new \RuntimeException('connect refused'));
        $this->outbox->method('getByIdempotencyKey')->willReturn(null);
        $savedEvent = $this->createMock(OutboxEventInterface::class);
        $savedEvent->method('setIdempotencyKey')->willReturnSelf();
        $savedEvent->method('setEventName')->willReturnSelf();
        $savedEvent->method('setPayload')->willReturnSelf();
        $savedEvent->method('setAttempts')->willReturnSelf();
        $savedEvent->method('setNextAttemptAt')->willReturnSelf();
        $savedEvent->method('setLastError')->willReturnSelf();
        $this->outboxFactory->method('create')->willReturn($savedEvent);
        $this->outbox->expects(self::once())->method('save');

        $result = $this->client->publishEvent('invoice.paid', ['magento_entity_id' => 1]);
        self::assertSame('', $result);
    }

    public function testPublishEventDoesNotDoubleEnqueueOnObserverReFire(): void
    {
        $this->curl->method('getStatus')->willReturn(503);
        $this->curl->method('getBody')->willReturn('');

        // Existing row in outbox — skip the insert; don't duplicate.
        $existing = $this->createMock(OutboxEventInterface::class);
        $this->outbox->method('getByIdempotencyKey')->willReturn($existing);
        $this->outboxFactory->expects(self::never())->method('create');
        $this->outbox->expects(self::never())->method('save');

        $result = $this->client->publishEvent('invoice.paid', ['magento_entity_id' => 1]);
        self::assertSame('', $result);
    }

    public function testFetchHealthReturnsEmptyArrayWhenNotConnected(): void
    {
        $disconnected = $this->createMock(ClientConfigInterface::class);
        $disconnected->method('isConnected')->willReturn(false);
        $disconnected->method('getTenantId')->willReturn(null);

        $client = new ByteClient(
            $disconnected,
            $this->signer,
            $this->curlFactory,
            $this->outbox,
            $this->outboxFactory,
            $this->cache,
            $this->logger,
            $this->entitySyncState
        );

        $this->curlFactory->expects(self::never())->method('create');
        self::assertSame([], $client->fetchHealth());
    }

    public function testFetchHealthServesFromCacheWhenWarm(): void
    {
        $cached = '{"tenant":{"magento_connection_status":"active"}}';
        $this->cache->method('load')->willReturn($cached);
        $this->curlFactory->expects(self::never())->method('create');

        $health = $this->client->fetchHealth();
        self::assertSame('active', $health['tenant']['magento_connection_status']);
    }

    public function testFetchHealthCachesFreshResponseWithHealthTag(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"binding":{"status":"active"}}');

        $this->cache->expects(self::once())
            ->method('save')
            ->with(
                self::stringContains('"status":"active"'),
                self::stringStartsWith('byte8_tile_health_'),
                [\Byte8\Client\Api\ByteClientInterface::HEALTH_CACHE_TAG],
                30
            );

        $this->client->fetchHealth();
    }

    public function testFetchHealthSwallowsTransientErrorsSoTileRendersGracefully(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->curl->method('getStatus')->willReturn(503);
        $this->curl->method('getBody')->willReturn('');
        $this->logger->expects(self::once())->method('warning');

        self::assertSame([], $this->client->fetchHealth());
    }

    public function testDisconnectSwallowsTransientErrors(): void
    {
        $this->curl->method('getStatus')->willReturn(503);
        $this->curl->method('getBody')->willReturn('');
        $this->logger->expects(self::once())->method('warning');

        self::assertFalse($this->client->disconnect());
    }

    public function testDisconnectReturnsTrueWhenLedgerAcknowledges(): void
    {
        $this->curl->method('getStatus')->willReturn(204);
        $this->curl->method('getBody')->willReturn('');

        self::assertTrue($this->client->disconnect());
    }

    public function testDisconnectNoOpsWhenNotConnected(): void
    {
        $disconnected = $this->createMock(ClientConfigInterface::class);
        $disconnected->method('isConnected')->willReturn(false);

        $client = new ByteClient(
            $disconnected,
            $this->signer,
            $this->curlFactory,
            $this->outbox,
            $this->outboxFactory,
            $this->cache,
            $this->logger,
            $this->entitySyncState
        );
        $this->curlFactory->expects(self::never())->method('create');

        self::assertTrue($client->disconnect());
    }

    public function testEnqueueEventWritesPendingMirrorRowWhenProviderProvided(): void
    {
        $this->outbox->method('getByIdempotencyKey')->willReturn(null);
        $savedEvent = $this->createMock(OutboxEventInterface::class);
        $savedEvent->method('setIdempotencyKey')->willReturnSelf();
        $savedEvent->method('setEventName')->willReturnSelf();
        $savedEvent->method('setPayload')->willReturnSelf();
        $savedEvent->method('setAttempts')->willReturnSelf();
        $savedEvent->method('setNextAttemptAt')->willReturnSelf();
        $savedEvent->method('setLastError')->willReturnSelf();
        $this->outboxFactory->method('create')->willReturn($savedEvent);
        $this->outbox->expects(self::once())->method('save');

        // PR7: write-through fires when provider key is passed.
        $this->entitySyncState->expects(self::once())
            ->method('markPending')
            ->with('invoice', 42, 'sage_accounting');

        $this->client->enqueueEvent(
            'invoice.created',
            ['magento_entity_id' => 42],
            'invoice.created:42',
            'sage_accounting'
        );
    }

    public function testEnqueueEventSkipsMirrorWriteWhenProviderNotProvided(): void
    {
        $this->outbox->method('getByIdempotencyKey')->willReturn(null);
        $savedEvent = $this->createMock(OutboxEventInterface::class);
        $savedEvent->method('setIdempotencyKey')->willReturnSelf();
        $savedEvent->method('setEventName')->willReturnSelf();
        $savedEvent->method('setPayload')->willReturnSelf();
        $savedEvent->method('setAttempts')->willReturnSelf();
        $savedEvent->method('setNextAttemptAt')->willReturnSelf();
        $savedEvent->method('setLastError')->willReturnSelf();
        $this->outboxFactory->method('create')->willReturn($savedEvent);
        $this->outbox->expects(self::once())->method('save');

        // No provider key → no mirror write (back-compat for legacy callers).
        $this->entitySyncState->expects(self::never())->method('markPending');

        $this->client->enqueueEvent('invoice.created', ['magento_entity_id' => 42]);
    }

    public function testEnqueueEventSwallowsMirrorWriteFailures(): void
    {
        $this->outbox->method('getByIdempotencyKey')->willReturn(null);
        $savedEvent = $this->createMock(OutboxEventInterface::class);
        $savedEvent->method('setIdempotencyKey')->willReturnSelf();
        $savedEvent->method('setEventName')->willReturnSelf();
        $savedEvent->method('setPayload')->willReturnSelf();
        $savedEvent->method('setAttempts')->willReturnSelf();
        $savedEvent->method('setNextAttemptAt')->willReturnSelf();
        $savedEvent->method('setLastError')->willReturnSelf();
        $this->outboxFactory->method('create')->willReturn($savedEvent);
        $this->outbox->expects(self::once())->method('save');

        // Mirror write fails — outbox enqueue must still complete cleanly.
        $this->entitySyncState->method('markPending')
            ->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects(self::once())->method('warning');

        $this->client->enqueueEvent(
            'invoice.created',
            ['magento_entity_id' => 42],
            null,
            'sage_accounting'
        );
    }
}
