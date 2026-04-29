<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api;

use Byte8\Client\Api\Data\OutboxEventInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

interface OutboxEventRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(OutboxEventInterface $event): OutboxEventInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): OutboxEventInterface;

    /**
     * Return a row by idempotency key, or null if none exists. Used by
     * the publisher to detect observer re-fires and upsert instead of
     * inserting duplicates.
     */
    public function getByIdempotencyKey(string $idempotencyKey): ?OutboxEventInterface;

    /**
     * Batch of `status=pending` rows whose next_attempt_at is now-or-past,
     * ordered oldest-first, capped at $limit. Dead-lettered + succeeded
     * rows are never returned — the drain must not touch them.
     *
     * @return OutboxEventInterface[]
     */
    public function getDueBatch(int $limit): array;

    /**
     * Count of dead-lettered rows. Drives the admin "events needing
     * attention" banner on the Sage config page.
     */
    public function countDeadLettered(): int;

    /**
     * Page of dead-lettered rows for operator inspection, ordered
     * most-recent-first.
     *
     * @return OutboxEventInterface[]
     */
    public function listDeadLettered(int $limit = 50, int $offset = 0): array;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(OutboxEventInterface $event): void;

    /**
     * Count of `succeeded` rows older than $days days (based on
     * `last_attempt_at`, falling back to `created_at` for safety).
     * Used by `--dry-run` on the cleanup CLI to preview.
     */
    public function countSucceededOlderThan(int $days): int;

    /**
     * Bulk-delete `succeeded` rows older than $days — executed as a
     * single SQL DELETE against the ResourceModel connection (no
     * row-by-row ORM) so monthly GC stays fast on large stores.
     *
     * NEVER deletes `pending` or `dead_lettered` rows. Callers must
     * trust this guarantee — the cron relies on it.
     *
     * @return int Rows deleted.
     */
    public function purgeSucceededOlderThan(int $days): int;
}
