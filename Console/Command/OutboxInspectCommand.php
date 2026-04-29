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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List dead-lettered outbox rows — events ledger rejected with a
 * deterministic 4xx (or that exhausted their transient retry budget).
 * Operator reviews the error, fixes the underlying issue (mapping,
 * missing reference, etc.), then calls `byte8:sage:outbox:requeue`
 * to flip the row back to pending for the cron to pick up.
 *
 * Tabular output — designed to be copy/pasteable into tickets.
 */
class OutboxInspectCommand extends Command
{
    private const COMMAND_NAME = 'byte8:sage:outbox:inspect';

    private const OPT_LIMIT = 'limit';
    private const OPT_FULL = 'full';

    public function __construct(
        private readonly OutboxEventRepositoryInterface $outboxRepository,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('List dead-lettered outbox events (ledger rejected with 4xx, or transient retries exhausted).')
            ->addOption(self::OPT_LIMIT, null, InputOption::VALUE_REQUIRED, 'Rows to show.', '50')
            ->addOption(self::OPT_FULL, null, InputOption::VALUE_NONE, 'Print full payloads + error bodies (normally truncated).');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption(self::OPT_LIMIT));
        $full = (bool) $input->getOption(self::OPT_FULL);

        $totalDead = $this->outboxRepository->countDeadLettered();
        if ($totalDead === 0) {
            $output->writeln('<info>No dead-lettered events — nothing to inspect.</info>');
            return Cli::RETURN_SUCCESS;
        }

        $rows = $this->outboxRepository->listDeadLettered($limit, 0);

        $output->writeln(sprintf(
            '<comment>Dead-lettered events: %d total (showing %d)</comment>',
            $totalDead,
            count($rows)
        ));
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['id', 'event', 'idempotency_key', 'last_attempt_at', 'http', 'error']);

        foreach ($rows as $row) {
            /** @var OutboxEventInterface $row */
            $table->addRow([
                (string) $row->getEntityId(),
                (string) $row->getEventName(),
                (string) $row->getIdempotencyKey(),
                (string) ($row->getLastAttemptAt() ?? '—'),
                $row->getLastStatusCode() === null ? '(transient)' : (string) $row->getLastStatusCode(),
                $full
                    ? (string) $row->getLastError()
                    : $this->truncate((string) $row->getLastError(), 120),
            ]);
        }

        $table->render();

        $output->writeln('');
        $output->writeln(
            '<info>Fix the underlying issue (mapping, reference data, etc.), then requeue:</info>'
        );
        $output->writeln('  bin/magento byte8:sage:outbox:requeue --id=&lt;entity_id&gt;');
        $output->writeln('  bin/magento byte8:sage:outbox:requeue --all   # every dead-lettered row');

        return Cli::RETURN_SUCCESS;
    }

    private function truncate(string $s, int $max): string
    {
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }
}
