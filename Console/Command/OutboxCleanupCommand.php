<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Console\Command;

use Byte8\Client\Api\OutboxEventRepositoryInterface;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manual counterpart to the `byte8_outbox_gc` daily cron. Deletes
 * succeeded outbox rows older than --days (default 30). Never touches
 * pending or dead_lettered rows — the repository enforces that.
 *
 * Use cases:
 *   - Running GC immediately after a migration / backfill to reclaim space
 *   - One-off cleanup with a custom horizon (e.g. --days=7)
 *   - --dry-run to preview the row count before committing
 *
 * Usage:
 *   bin/magento byte8:sage:outbox:cleanup
 *   bin/magento byte8:sage:outbox:cleanup --days=7
 *   bin/magento byte8:sage:outbox:cleanup --days=14 --dry-run
 */
class OutboxCleanupCommand extends Command
{
    private const COMMAND_NAME = 'byte8:sage:outbox:cleanup';

    private const OPT_DAYS = 'days';
    private const OPT_DRY_RUN = 'dry-run';

    private const DEFAULT_DAYS = 30;
    private const MIN_DAYS = 1;

    public function __construct(
        private readonly OutboxEventRepositoryInterface $outboxRepository,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription(
                'Delete succeeded outbox events older than --days (default '
                . self::DEFAULT_DAYS . '). Never touches pending / dead-lettered.'
            )
            ->addOption(
                self::OPT_DAYS,
                null,
                InputOption::VALUE_REQUIRED,
                'Retention horizon in days — succeeded rows older than this are deleted.',
                (string) self::DEFAULT_DAYS
            )
            ->addOption(
                self::OPT_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Report the row count that would be deleted, then exit without touching the table.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $daysRaw = $input->getOption(self::OPT_DAYS);
        $days = is_numeric($daysRaw) ? (int) $daysRaw : -1;
        if ($days < self::MIN_DAYS) {
            $output->writeln(sprintf(
                '<error>--days must be an integer ≥ %d (got: %s). '
                . 'Refusing to run with a horizon that could wipe fresh audit data.</error>',
                self::MIN_DAYS,
                var_export($daysRaw, true)
            ));
            return Cli::RETURN_FAILURE;
        }

        if ($input->getOption(self::OPT_DRY_RUN)) {
            $count = $this->outboxRepository->countSucceededOlderThan($days);
            $output->writeln(sprintf(
                '<info>Dry-run: %d succeeded row%s older than %d day%s would be deleted.</info>',
                $count,
                $count === 1 ? '' : 's',
                $days,
                $days === 1 ? '' : 's'
            ));
            return Cli::RETURN_SUCCESS;
        }

        $deleted = $this->outboxRepository->purgeSucceededOlderThan($days);
        $output->writeln(sprintf(
            '<info>Purged %d succeeded outbox row%s older than %d day%s.</info>',
            $deleted,
            $deleted === 1 ? '' : 's',
            $days,
            $days === 1 ? '' : 's'
        ));

        return Cli::RETURN_SUCCESS;
    }
}
