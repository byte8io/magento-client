<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Byte8\Client\Api\EntitySyncStateRepositoryInterface;
use Byte8\Client\Api\SyncStateUpsertInterface;
use Magento\Framework\Exception\InputException;

class SyncStateUpsert implements SyncStateUpsertInterface
{
    /**
     * Allowed values for the inbound `sync_status` field. `pending` is
     * Magento-side only (see EntitySyncStateRepository::markPending) and
     * MUST NOT arrive via the ledger callback — reject loudly so we never
     * silently regress a `synced`/`failed` row.
     */
    private const ALLOWED_STATUSES = [
        EntitySyncStateInterface::STATUS_SYNCED,
        EntitySyncStateInterface::STATUS_SKIPPED,
        EntitySyncStateInterface::STATUS_FAILED,
    ];

    private const ALLOWED_ENTITY_TYPES = [
        EntitySyncStateInterface::ENTITY_TYPE_INVOICE,
        EntitySyncStateInterface::ENTITY_TYPE_CREDITMEMO,
        EntitySyncStateInterface::ENTITY_TYPE_CUSTOMER,
        EntitySyncStateInterface::ENTITY_TYPE_PRODUCT,
    ];

    public function __construct(
        private readonly EntitySyncStateRepositoryInterface $repository
    ) {
    }

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
    ): void {
        if (!in_array($entityType, self::ALLOWED_ENTITY_TYPES, true)) {
            throw new InputException(
                __('Unknown entity_type %1; expected one of: %2',
                    $entityType,
                    implode(', ', self::ALLOWED_ENTITY_TYPES))
            );
        }
        if ($magentoEntityId <= 0) {
            throw new InputException(__('magento_entity_id must be positive'));
        }
        if ($provider === '') {
            throw new InputException(__('provider must be non-empty'));
        }
        if (!in_array($syncStatus, self::ALLOWED_STATUSES, true)) {
            throw new InputException(
                __(
                    'Invalid sync_status %1; pending is Magento-side only and must not arrive via callback. Expected: %2',
                    $syncStatus,
                    implode(', ', self::ALLOWED_STATUSES)
                )
            );
        }

        // Default to NOW() in UTC if the caller didn't supply a timestamp
        // — defensive, the ledger always populates it but a future
        // hand-curl shouldn't break.
        $effectiveTs = $lastSyncAt
            ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->repository->upsertFromCallback(
            $entityType,
            $magentoEntityId,
            $provider,
            $syncStatus,
            $providerEntityId,
            $providerReference,
            $skipReason,
            $errorCode,
            $effectiveTs
        );
    }
}
