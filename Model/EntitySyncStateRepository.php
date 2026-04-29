<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Byte8\Client\Api\EntitySyncStateRepositoryInterface;
use Byte8\Client\Model\ResourceModel\EntitySyncState as EntitySyncStateResource;
use Byte8\Client\Model\ResourceModel\EntitySyncState\CollectionFactory;
use Magento\Framework\Exception\CouldNotSaveException;

class EntitySyncStateRepository implements EntitySyncStateRepositoryInterface
{
    public function __construct(
        private readonly EntitySyncStateResource $resource,
        private readonly EntitySyncStateFactory $factory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * INSERT … ON DUPLICATE KEY UPDATE last_sync_at = VALUES(last_sync_at).
     *
     * The DO-NOTHING-ON-CONFLICT semantics matter: if a prior sync left
     * a `synced` / `failed` row, that terminal status stays authoritative
     * — we don't want a second observer fire (e.g. invoice.paid arriving
     * after invoice.created already synced) to flip the chip back to
     * pending. We do bump `last_sync_at` so the detail page's "last
     * pending check at" timestamp is fresh, but the status enum stays
     * untouched.
     */
    public function markPending(string $entityType, int $magentoId, string $provider): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getMainTable();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // Use raw INSERT … ON DUPLICATE KEY UPDATE so the composite
        // unique index does the dedup atomically — no SELECT-then-INSERT
        // race window. Touching only `last_sync_at` on conflict keeps
        // the prior terminal status intact.
        $connection->query(
            sprintf(
                'INSERT INTO %s (entity_type, magento_id, provider, sync_status, last_sync_at) '
                . 'VALUES (?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE last_sync_at = VALUES(last_sync_at)',
                $connection->quoteIdentifier($table)
            ),
            [
                $entityType,
                $magentoId,
                $provider,
                EntitySyncStateInterface::STATUS_PENDING,
                $now,
            ]
        );
    }

    /**
     * Always-overwrites UPSERT — terminal callbacks from ledger are
     * authoritative.
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
    ): EntitySyncStateInterface {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getMainTable();

        try {
            $connection->query(
                sprintf(
                    'INSERT INTO %s '
                    . '(entity_type, magento_id, provider, sync_status, provider_entity_id, '
                    . ' provider_reference, skip_reason, error_code, last_sync_at) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE '
                    . ' sync_status = VALUES(sync_status), '
                    . ' provider_entity_id = VALUES(provider_entity_id), '
                    . ' provider_reference = VALUES(provider_reference), '
                    . ' skip_reason = VALUES(skip_reason), '
                    . ' error_code = VALUES(error_code), '
                    . ' last_sync_at = VALUES(last_sync_at)',
                    $connection->quoteIdentifier($table)
                ),
                [
                    $entityType,
                    $magentoId,
                    $provider,
                    $syncStatus,
                    $providerEntityId,
                    $providerReference,
                    $skipReason,
                    $errorCode,
                    $lastSyncAt,
                ]
            );
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(
                __('Could not upsert sync state for %1/%2: %3', $entityType, $magentoId, $e->getMessage()),
                $e
            );
        }

        $row = $this->find($entityType, $magentoId, $provider);
        if ($row === null) {
            throw new CouldNotSaveException(
                __(
                    'Sync state row vanished immediately after UPSERT for %1/%2 — replication lag or race?',
                    $entityType,
                    $magentoId
                )
            );
        }
        return $row;
    }

    public function find(string $entityType, int $magentoId, string $provider): ?EntitySyncStateInterface
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(EntitySyncStateInterface::ENTITY_TYPE, $entityType);
        $collection->addFieldToFilter(EntitySyncStateInterface::MAGENTO_ID, $magentoId);
        $collection->addFieldToFilter(EntitySyncStateInterface::PROVIDER, $provider);
        $collection->setPageSize(1);

        $items = array_values($collection->getItems());
        return $items[0] ?? null;
    }
}
