<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Data;

/**
 * One row in `byte8_entity_sync_state` — Magento-side mirror of ledger
 * sync outcomes, keyed on `(entity_type, magento_id, provider)`. PR7.
 *
 * Read model only. Two writers:
 *
 * - `ByteClient::enqueueEvent` UPSERTs `STATUS_PENDING` immediately
 *   when an observer publishes (e.g. invoice.created). The grid then
 *   renders a chip *immediately* without waiting for the cron drain.
 * - `POST /V1/byte8/sync-state` (inbound from ledger worker) UPSERTs
 *   the terminal status (`STATUS_SYNCED` / `STATUS_SKIPPED` /
 *   `STATUS_FAILED`) when the ledger sync_run terminates.
 *
 * Magento admin grids LEFT JOIN against this row on
 * `(entity_type, magento_id, provider)` to render the "Sage Status"
 * chip — see `module-sage-accounting` ui_component XML.
 */
interface EntitySyncStateInterface
{
    public const DB_TABLE_NAME = 'byte8_entity_sync_state';

    public const ENTITY_ID = 'entity_id';
    public const ENTITY_TYPE = 'entity_type';
    public const MAGENTO_ID = 'magento_id';
    public const PROVIDER = 'provider';
    public const SYNC_STATUS = 'sync_status';
    public const PROVIDER_ENTITY_ID = 'provider_entity_id';
    public const PROVIDER_REFERENCE = 'provider_reference';
    public const SKIP_REASON = 'skip_reason';
    public const ERROR_CODE = 'error_code';
    public const LAST_SYNC_AT = 'last_sync_at';

    /**
     * Stable entity-type codes — must match the strings the ledger worker
     * uses in `magento_entity_for_push` (queue_drain.rs) and the prefixes
     * the observers emit on `event_name` (e.g. `invoice.created` → `invoice`).
     */
    public const ENTITY_TYPE_INVOICE = 'invoice';
    public const ENTITY_TYPE_CREDITMEMO = 'creditmemo';
    public const ENTITY_TYPE_CUSTOMER = 'customer';
    public const ENTITY_TYPE_PRODUCT = 'product';

    /**
     * Status enum — kept in sync with the values the ledger callback POSTs
     * and with Magento UI's chip rendering. `pending` is Magento-side only
     * (set at outbox enqueue time); the other three come from the ledger
     * callback after a terminal `SyncRun::mark_*`.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public function getEntityId(): ?int;

    public function getEntityType(): string;

    public function setEntityType(string $entityType): self;

    public function getMagentoId(): int;

    public function setMagentoId(int $magentoId): self;

    public function getProvider(): string;

    public function setProvider(string $provider): self;

    public function getSyncStatus(): string;

    public function setSyncStatus(string $syncStatus): self;

    public function getProviderEntityId(): ?string;

    public function setProviderEntityId(?string $providerEntityId): self;

    public function getProviderReference(): ?string;

    public function setProviderReference(?string $providerReference): self;

    public function getSkipReason(): ?string;

    public function setSkipReason(?string $skipReason): self;

    public function getErrorCode(): ?string;

    public function setErrorCode(?string $errorCode): self;

    public function getLastSyncAt(): ?string;

    public function setLastSyncAt(string $lastSyncAt): self;
}
