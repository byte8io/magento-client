<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Console\Command;

use Byte8\Client\Api\Data\OutboxEventInterface;
use Byte8\Client\Api\OutboxEventRepositoryInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\NoSuchEntityException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Flip a dead-lettered outbox row back to `pending` so the cron drain
 * retries delivery. Intended to be run AFTER the merchant has fixed
 * the underlying cause of the 4xx (e.g. added the missing Sage tax
 * code to reference_cache, corrected the mapping, etc).
 *
 * Resets `attempts` and clears `next_attempt_at` so the drain picks
 * the row up on the next tick. `last_error` and `last_status_code`
 * are preserved as audit trail — you can see what the prior failure
 * was even after a successful re-attempt.
 *
 * Usage:
 *   bin/magento byte8:sage:outbox:requeue --id=42
 *   bin/magento byte8:sage:outbox:requeue --all
 */
class OutboxRequeueCommand extends Command
{
    private const COMMAND_NAME = 'byte8:sage:outbox:requeue';

    private const OPT_ID = 'id';
    private const OPT_ALL = 'all';

    public function __construct(
        private readonly OutboxEventRepositoryInterface $outboxRepository,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Flip dead-lettered outbox rows back to pending so the drain retries them.')
            ->addOption(self::OPT_ID, null, InputOption::VALUE_REQUIRED, 'A single outbox entity_id to requeue.')
            ->addOption(self::OPT_ALL, null, InputOption::VALUE_NONE, 'Requeue every dead-lettered row.');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption(self::OPT_ID);
        $all = (bool) $input->getOption(self::OPT_ALL);

        $selectors = array_filter([$id !== null, $all]);
        if (count($selectors) !== 1) {
            $output->writeln('<error>Exactly one of --id or --all is required.</error>');
            return Cli::RETURN_FAILURE;
        }

        if ($id !== null) {
            return $this->requeueOne((int) $id, $output);
        }

        return $this->requeueAll($output);
    }

    private function requeueOne(int $id, OutputInterface $output): int
    {
        try {
            $event = $this->outboxRepository->getById($id);
        } catch (NoSuchEntityException) {
            $output->writeln("<error>Outbox row {$id} not found.</error>");
            return Cli::RETURN_FAILURE;
        }

        if ($event->getStatus() !== OutboxEventInterface::STATUS_DEAD_LETTERED) {
            $output->writeln(sprintf(
                '<comment>Outbox row %d is status=%s (not dead_lettered) — nothing to do.</comment>',
                $id,
                $event->getStatus()
            ));
            return Cli::RETURN_SUCCESS;
        }

        $this->flipToPending($event);
        $output->writeln("<info>Outbox row {$id} flipped back to pending — cron will retry within 60s.</info>");

        return Cli::RETURN_SUCCESS;
    }

    private function requeueAll(OutputInterface $output): int
    {
        // Page through 50 at a time so we don't pull megabytes of
        // payload text into memory on a bad month.
        $flipped = 0;
        $pageSize = 50;
        while (true) {
            $rows = $this->outboxRepository->listDeadLettered($pageSize, 0);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                /** @var OutboxEventInterface $row */
                $this->flipToPending($row);
                $flipped++;
            }
            // Re-query from the top — flipped rows now have status=pending
            // and drop out of listDeadLettered, so the next page starts
            // at offset 0 and the loop terminates naturally.
        }

        $output->writeln(sprintf(
            '<info>Flipped %d dead-lettered row%s back to pending. Cron drains within 60s.</info>',
            $flipped,
            $flipped === 1 ? '' : 's'
        ));

        return Cli::RETURN_SUCCESS;
    }

    private function flipToPending(OutboxEventInterface $event): void
    {
        $event->setStatus(OutboxEventInterface::STATUS_PENDING)
            ->setAttempts(0)
            ->setNextAttemptAt(null);
        // Intentionally preserve last_status_code + last_error +
        // last_attempt_at — the audit trail of what went wrong is
        // often the most useful field for a support ticket even
        // after a successful requeue.
        $this->outboxRepository->save($event);
    }
}
