<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Command;

use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Integrity\AuditEntrySignature;
use Metadev\DoctrineAuditTrailBundle\Integrity\SignatureProviderInterface;
use Metadev\DoctrineAuditTrailBundle\Repository\AuditTrailEntryRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'audit:actor-anonymise',
    description: 'Anonymises in-place the actor PII of every audit entry for a given user (GDPR art. 17).',
)]
final class ActorAnonymiseAuditTrailCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 500;
    private const ANONYMISED_ACTOR_LABEL = 'gdpr-anonymised';
    private const ANONYMISED_IDENTIFIER_PREFIX = 'gdpr-';

    public function __construct(
        private readonly AuditTrailEntryRepository $repository,
        private readonly ?SignatureProviderInterface $signatureProvider = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'user-identifier',
                null,
                InputOption::VALUE_REQUIRED,
                'The userIdentifier value matched against the audit_trail table. Every row attributed to this actor is anonymised.',
            )
            ->addOption(
                'reason',
                null,
                InputOption::VALUE_REQUIRED,
                'Free-text justification recorded in the operational log (e.g. "GDPR-art-17 request #1234"). Required for traceability.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report how many entries would be anonymised without touching the table.',
            )
            ->addOption(
                'batch',
                null,
                InputOption::VALUE_REQUIRED,
                'Rows processed per round-trip. Smaller batches keep transactions short on large tables.',
                (string) self::DEFAULT_BATCH_SIZE,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userIdentifier = trim((string) ($input->getOption('user-identifier') ?? ''));
        if ('' === $userIdentifier) {
            return $this->fail($io, 'missing_user_identifier', 'Missing --user-identifier: provide the actor identifier whose audit rows must be anonymised.');
        }

        $reason = trim((string) ($input->getOption('reason') ?? ''));
        if ('' === $reason) {
            return $this->fail($io, 'missing_reason', 'Missing --reason: provide a justification (e.g. "GDPR-art-17 request #1234") for the audit log.');
        }

        $batchSize = (int) $input->getOption('batch');
        if ($batchSize < 1) {
            return $this->fail($io, 'invalid_batch_size', \sprintf('--batch must be >= 1, got %d.', $batchSize));
        }

        $userIdentifierHash = self::hashIdentifier($userIdentifier);

        $dryRun = (bool) $input->getOption('dry-run');
        $start = microtime(true);

        if ($dryRun) {
            $count = $this->repository->countActorAnonymisableForActor($userIdentifier);
            $io->success(\sprintf('Dry-run: %d entry(ies) would be anonymised.', $count));
            $this->logSummary($userIdentifierHash, $reason, $count, true, $batchSize, $start);

            return Command::SUCCESS;
        }

        $totalAnonymised = 0;

        do {
            $batch = $this->repository->findActorAnonymisableBatch($userIdentifier, $batchSize);
            $this->repository->transactional(function () use ($batch, $userIdentifierHash): void {
                foreach ($batch as $entry) {
                    $this->anonymiseEntry($entry, $userIdentifierHash);
                }
            });
            $totalAnonymised += \count($batch);
        } while ([] !== $batch);

        $io->success(\sprintf('Anonymised %d entry(ies).', $totalAnonymised));
        $this->logSummary($userIdentifierHash, $reason, $totalAnonymised, false, $batchSize, $start);

        return Command::SUCCESS;
    }

    private function anonymiseEntry(AuditTrailEntry $entry, string $userIdentifierHash): void
    {
        $anonymisedAt = new \DateTimeImmutable();

        $userId = $entry->getUserId();
        $anonymisedUserId = null === $userId ? null : self::hashIdentifier($userId);

        $newSignature = null === $this->signatureProvider ? null : $this->signatureProvider->sign(
            AuditEntrySignature::payload(
                entityClass: $entry->getEntityClass(),
                entityId: $entry->getEntityId(),
                action: $entry->getAction(),
                diff: $entry->getDiff(),
                userId: $anonymisedUserId,
                userIdentifier: $userIdentifierHash,
                ipAddress: null,
                userAgent: null,
                actorLabel: self::ANONYMISED_ACTOR_LABEL,
                createdAt: $entry->getCreatedAt(),
                actorAnonymisedAt: $anonymisedAt,
            ),
        );

        $id = $entry->getId();
        if (null === $id) {
            return;
        }

        $this->repository->applyActorAnonymisation(
            $id,
            $anonymisedUserId,
            $userIdentifierHash,
            self::ANONYMISED_ACTOR_LABEL,
            $newSignature,
            $anonymisedAt,
        );
    }

    private function fail(SymfonyStyle $io, string $reason, string $message): int
    {
        $io->error($message);
        $this->logger?->warning('audit.actor_anonymise.rejected', ['reason' => $reason, 'message' => $message]);

        return Command::INVALID;
    }

    private function logSummary(
        string $userIdentifierHash,
        string $reason,
        int $count,
        bool $dryRun,
        int $batchSize,
        float $start,
    ): void {
        $this->logger?->info('audit.actor_anonymise.completed', [
            'user_identifier_hash' => $userIdentifierHash,
            'reason' => $reason,
            'anonymised' => $count,
            'dry_run' => $dryRun,
            'batch_size' => $batchSize,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ]);
    }

    private static function hashIdentifier(string $value): string
    {
        return self::ANONYMISED_IDENTIFIER_PREFIX.hash('sha256', $value);
    }
}
