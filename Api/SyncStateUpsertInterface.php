<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;

/**
 * Inbound `POST /V1/byte8/sync-state` — called by the ledger worker after
 * every terminal `SyncRun::mark_*`. Idempotent on
 * `(entity_type, magento_id, provider)`. PR7.
 *
 * Request body shape (matches the JSON the ledger worker POSTs):
 * ```
 * {
 *   "entity_type":         "invoice" | "creditmemo" | "customer" | "product",
 *   "magento_entity_id":   42,
 *   "provider":            "sage_accounting",
 *   "sync_status":         "synced" | "skipped" | "failed",
 *   "provider_entity_id":  "df6b646cf16…" | null,
 *   "provider_reference":  "SI-27" | null,           // v1.1+; today always null
 *   "skip_reason":         "payment_method_not_mapped" | null,
 *   "error_code":          "provider" | "http" | … | null,
 *   "last_sync_at":        "2026-04-29T10:11:12Z"
 * }
 * ```
 *
 * Returns 204. The Magento side never sends a "pending" status via this
 * endpoint — pending rows are written locally by `ByteClient::enqueueEvent`.
 */
interface SyncStateUpsertInterface
{
    /**
     * @param string      $entityType
     * @param int         $magentoEntityId
     * @param string      $provider
     * @param string      $syncStatus
     * @param string|null $providerEntityId
     * @param string|null $providerReference
     * @param string|null $skipReason
     * @param string|null $errorCode
     * @param string|null $lastSyncAt
     *
     * @return void
     * @throws InputException        On missing / malformed required fields.
     * @throws CouldNotSaveException On persistence failure.
     */
    public function upsert(
        string $entityType,
        int $magentoEntityId,
        string $provider,
        string $syncStatus,
        ?string $providerEntityId = null,
        ?string $providerReference = null,
        ?string $skipReason = null,
        ?string $errorCode = null,
        ?string $lastSyncAt = null
    ): void;
}
