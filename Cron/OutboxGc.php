<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Cron;

use Byte8\Client\Api\OutboxEventRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily garbage collector for the `succeeded` slice of
 * byte8_event_outbox. Runs after the main drain cadence has had a
 * chance to flip today's pending work; deletes rows whose
 * `last_attempt_at` is older than RETENTION_DAYS.
 *
 * Never touches `pending` (in-flight) or `dead_lettered` (needs
 * operator attention) rows — the repository enforces that on every
 * DELETE. The cron only supplies the retention horizon.
 *
 * 30 days is the default retention — long enough for a month-end
 * audit trail, short enough that the table stays tidy. Adjust the
 * constant only if support / compliance demand it; the matching
 * CLI (`byte8:sage:outbox:cleanup --days=N`) is the better place
 * for one-off runs with a different horizon.
 */
class OutboxGc
{
    private const RETENTION_DAYS = 30;

    public function __construct(
        private readonly OutboxEventRepositoryInterface $outboxRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $deleted = $this->outboxRepository->purgeSucceededOlderThan(self::RETENTION_DAYS);
        if ($deleted > 0) {
            $this->logger->info(sprintf(
                'Byte8 outbox GC: purged %d succeeded event row%s older than %d days',
                $deleted,
                $deleted === 1 ? '' : 's',
                self::RETENTION_DAYS
            ));
        }
    }
}
