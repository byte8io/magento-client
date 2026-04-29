<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Repository for `byte8_entity_sync_state` — Magento-side mirror of the
 * ledger sync state. PR7.
 */
interface EntitySyncStateRepositoryInterface
{
    /**
     * Mark `(entity_type, magento_id, provider)` as `pending` if no row
     * exists yet, or leave the row's status unchanged if one already
     * does (a `synced`/`failed` row from the previous sync stays
     * authoritative — the next callback is allowed to overwrite it).
     *
     * Called from `ByteClient::enqueueEvent` so the admin grid shows
     * the `⏳ Pending` chip immediately after the merchant raises an
     * invoice — without waiting for the cron drain or the ledger
     * callback. Safe to call repeatedly.
     */
    public function markPending(string $entityType, int $magentoId, string $provider): void;

    /**
     * UPSERT the terminal outcome from a ledger callback. Always wins
     * over an existing row (terminal status from ledger is authoritative).
     *
     * @throws CouldNotSaveException when persistence fails.
     */
    public function upsertFromCallback(
        string $entityType,
        int $magentoId,
        string $provider,
        string $syncStatus,
        ?string $providerEntityId,
        ?string $providerReference,
        ?string $skipReason,
        ?string $errorCode,
        string $lastSyncAt
    ): EntitySyncStateInterface;

    /**
     * Read by composite key. Returns null when the entity has never
     * been synced (pre-PR7 backlog rows or pre-install entities).
     */
    public function find(string $entityType, int $magentoId, string $provider): ?EntitySyncStateInterface;
}
