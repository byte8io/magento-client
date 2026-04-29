<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Data;

/**
 * One row in byte8_event_outbox — the primary delivery queue for every
 * outbound Byte8 SaaS event. Observers always enqueue here; OutboxDrain
 * cron performs the actual HTTP delivery.
 *
 * Terminal-state model (see db_schema.xml):
 *   - STATUS_PENDING       in flight, drain will (re)attempt
 *   - STATUS_DEAD_LETTERED  deterministic failure (4xx) or exhausted
 *                           transient retries — drain never touches
 *                           these; requires operator intervention
 *   - STATUS_SUCCEEDED      delivered to ledger
 */
interface OutboxEventInterface
{
    public const DB_TABLE_NAME = 'byte8_event_outbox';

    public const ENTITY_ID = 'entity_id';
    public const IDEMPOTENCY_KEY = 'idempotency_key';
    public const EVENT_NAME = 'event_name';
    public const PAYLOAD = 'payload';
    public const STATUS = 'status';
    public const ATTEMPTS = 'attempts';
    public const LAST_STATUS_CODE = 'last_status_code';
    public const LAST_ATTEMPT_AT = 'last_attempt_at';
    public const NEXT_ATTEMPT_AT = 'next_attempt_at';
    public const LAST_ERROR = 'last_error';
    public const CREATED_AT = 'created_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DEAD_LETTERED = 'dead_lettered';
    public const STATUS_SUCCEEDED = 'succeeded';

    public function getEntityId(): ?int;

    public function getIdempotencyKey(): ?string;

    public function setIdempotencyKey(string $idempotencyKey): self;

    public function getEventName(): ?string;

    public function setEventName(string $eventName): self;

    public function getPayload(): ?string;

    public function setPayload(string $payload): self;

    public function getStatus(): string;

    public function setStatus(string $status): self;

    public function getAttempts(): int;

    public function setAttempts(int $attempts): self;

    public function getLastStatusCode(): ?int;

    public function setLastStatusCode(?int $code): self;

    public function getLastAttemptAt(): ?string;

    public function setLastAttemptAt(?string $ts): self;

    public function getNextAttemptAt(): ?string;

    public function setNextAttemptAt(?string $nextAttemptAt): self;

    public function getLastError(): ?string;

    public function setLastError(?string $lastError): self;

    public function getCreatedAt(): ?string;
}
