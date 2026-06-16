<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Command;

use Metadev\DoctrineAuditTrailBundle\Repository\AuditTrailEntryRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'audit:prune',
    description: 'Deletes audit trail entries older than a cutoff (retention policy).',
)]
final class PruneAuditTrailCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 1000;

    public function __construct(
        private readonly AuditTrailEntryRepository $repository,
        private readonly ?string $defaultAge = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'before',
                null,
                InputOption::VALUE_REQUIRED,
                'Cutoff date or relative spec parseable by DateTimeImmutable (e.g. "-7 years", "2020-01-01"). Falls back to the "retention.default_age" config when omitted. Must resolve to a moment strictly in the past.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would be deleted without touching the table.',
            )
            ->addOption(
                'batch',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of rows deleted per round-trip. Smaller batches keep transactions short on large tables.',
                (string) self::DEFAULT_BATCH_SIZE,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawSpec = $input->getOption('before') ?? $this->defaultAge;
        $spec = trim((string) ($rawSpec ?? ''));
        if ('' === $spec) {
            return $this->fail($io, 'missing_cutoff', 'Missing cutoff: provide --before or configure doctrine_audit_trail.retention.default_age.');
        }

        // $now is captured BEFORE parsing the spec so that strings like "now"
        // — which resolve to the parse timestamp, microseconds later — fail
        // the strict-past check and don't silently wipe the whole table.
        $now = new \DateTimeImmutable();
        try {
            $cutoff = new \DateTimeImmutable($spec);
        } catch (\Exception $e) {
            return $this->fail($io, 'invalid_cutoff', \sprintf('Invalid --before value "%s": %s', $spec, $e->getMessage()));
        }

        if ($cutoff >= $now) {
            return $this->fail($io, 'cutoff_not_in_past', \sprintf(
                'Refusing to prune: cutoff "%s" (%s) is not strictly in the past. Pass an older --before to avoid wiping live entries.',
                $spec,
                $cutoff->format(\DATE_ATOM),
            ));
        }

        $batchSize = (int) $input->getOption('batch');
        if ($batchSize < 1) {
            return $this->fail($io, 'invalid_batch_size', \sprintf('--batch must be >= 1, got %d.', $batchSize));
        }

        $io->writeln(\sprintf('Cutoff: <info>%s</info> (entries strictly older are eligible).', $cutoff->format(\DATE_ATOM)));

        $dryRun = (bool) $input->getOption('dry-run');
        $start = microtime(true);

        if ($dryRun) {
            $count = $this->repository->countOlderThan($cutoff);
            $io->success(\sprintf('Dry-run: %d entry(ies) would be deleted.', $count));
            $this->logSummary($cutoff, $count, true, $batchSize, $start);

            return Command::SUCCESS;
        }

        $totalDeleted = 0;
        do {
            $deleted = $this->repository->pruneOlderThan($cutoff, $batchSize);
            $totalDeleted += $deleted;
        } while ($deleted > 0);

        $io->success(\sprintf('Pruned %d entry(ies).', $totalDeleted));
        $this->logSummary($cutoff, $totalDeleted, false, $batchSize, $start);

        return Command::SUCCESS;
    }

    private function fail(SymfonyStyle $io, string $reason, string $message): int
    {
        $io->error($message);
        $this->logger?->warning('audit.prune.rejected', ['reason' => $reason, 'message' => $message]);

        return Command::INVALID;
    }

    private function logSummary(\DateTimeImmutable $cutoff, int $count, bool $dryRun, int $batchSize, float $start): void
    {
        $this->logger?->info('audit.prune.completed', [
            'cutoff' => $cutoff->format(\DATE_ATOM),
            'deleted' => $count,
            'dry_run' => $dryRun,
            'batch_size' => $batchSize,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ]);
    }
}
