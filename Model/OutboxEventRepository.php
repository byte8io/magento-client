<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model;

use Byte8\Client\Api\Data\OutboxEventInterface;
use Byte8\Client\Api\OutboxEventRepositoryInterface;
use Byte8\Client\Model\ResourceModel\OutboxEvent as OutboxEventResource;
use Byte8\Client\Model\ResourceModel\OutboxEvent\CollectionFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class OutboxEventRepository implements OutboxEventRepositoryInterface
{
    public function __construct(
        private readonly OutboxEventResource $resource,
        private readonly OutboxEventFactory $factory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function save(OutboxEventInterface $event): OutboxEventInterface
    {
        try {
            $this->resource->save($event);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(
                __('Could not save outbox event: %1', $e->getMessage()),
                $e
            );
        }
        return $event;
    }

    public function getById(int $entityId): OutboxEventInterface
    {
        $event = $this->factory->create();
        $this->resource->load($event, $entityId);
        if (!$event->getEntityId()) {
            throw new NoSuchEntityException(__('Outbox event %1 does not exist.', $entityId));
        }
        return $event;
    }

    public function getByIdempotencyKey(string $idempotencyKey): ?OutboxEventInterface
    {
        $event = $this->factory->create();
        $this->resource->load($event, $idempotencyKey, OutboxEventInterface::IDEMPOTENCY_KEY);
        return $event->getEntityId() ? $event : null;
    }

    /**
     * @inheritDoc
     */
    public function getDueBatch(int $limit): array
    {
        $collection = $this->collectionFactory->create();
        // Drain ONLY `pending`. Dead-lettered + succeeded rows are
        // terminal and must never be re-attempted by the cron.
        $collection->addFieldToFilter(
            OutboxEventInterface::STATUS,
            OutboxEventInterface::STATUS_PENDING
        );
        $collection->addFieldToFilter(
            OutboxEventInterface::NEXT_ATTEMPT_AT,
            [
                ['null' => true],
                ['lteq' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
            ]
        );
        $collection->setOrder(OutboxEventInterface::ENTITY_ID, 'ASC');
        $collection->setPageSize($limit);

        return array_values($collection->getItems());
    }

    public function countDeadLettered(): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(
            OutboxEventInterface::STATUS,
            OutboxEventInterface::STATUS_DEAD_LETTERED
        );
        return (int) $collection->getSize();
    }

    public function listDeadLettered(int $limit = 50, int $offset = 0): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(
            OutboxEventInterface::STATUS,
            OutboxEventInterface::STATUS_DEAD_LETTERED
        );
        $collection->setOrder(OutboxEventInterface::LAST_ATTEMPT_AT, 'DESC');
        $collection->setPageSize($limit);
        $collection->setCurPage($offset > 0 ? (int) floor($offset / max($limit, 1)) + 1 : 1);

        return array_values($collection->getItems());
    }

    public function delete(OutboxEventInterface $event): void
    {
        try {
            $this->resource->delete($event);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(
                __('Could not delete outbox event: %1', $e->getMessage()),
                $e
            );
        }
    }

    public function countSucceededOlderThan(int $days): int
    {
        if ($days < 0) {
            return 0;
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getMainTable();
        $cutoff = $this->cutoffFor($days);

        $select = $connection->select()
            ->from($table, ['cnt' => 'COUNT(*)'])
            ->where('status = ?', OutboxEventInterface::STATUS_SUCCEEDED)
            ->where('COALESCE(last_attempt_at, created_at) < ?', $cutoff);

        return (int) $connection->fetchOne($select);
    }

    public function purgeSucceededOlderThan(int $days): int
    {
        if ($days < 0) {
            return 0;
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getMainTable();
        $cutoff = $this->cutoffFor($days);

        // Single batched DELETE. The WHERE is anchored on `status` so
        // we cannot nuke pending / dead_lettered rows even with a
        // malformed $days. COALESCE covers rows that predate the new
        // `last_attempt_at` column (in practice only possible during
        // the 0→1 upgrade when status also backfilled to succeeded).
        return (int) $connection->delete(
            $table,
            [
                'status = ?' => OutboxEventInterface::STATUS_SUCCEEDED,
                'COALESCE(last_attempt_at, created_at) < ?' => $cutoff,
            ]
        );
    }

    private function cutoffFor(int $days): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . max($days, 0) . ' days')
            ->format('Y-m-d H:i:s');
    }
}
