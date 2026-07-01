<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Command;

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
    name: 'audit:verify',
    description: 'Verifies the HMAC tamper-evidence seal of every audit trail entry.',
)]
final class VerifyAuditTrailCommand extends Command
{
    private const FORMAT_TEXT = 'text';
    private const FORMAT_JSON = 'json';

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
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: "text" (default) or "json" for SOC/SIEM pipelines.', self::FORMAT_TEXT)
            ->addOption('fail-fast', null, InputOption::VALUE_NONE, 'Stop and exit at the first tampered entry (useful in monitoring cron).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!\in_array($format, [self::FORMAT_TEXT, self::FORMAT_JSON], true)) {
            (new SymfonyStyle($input, $output))->error(\sprintf('Invalid --format "%s". Use "text" or "json".', $format));

            return Command::INVALID;
        }

        $failFast = (bool) $input->getOption('fail-fast');
        $io = new SymfonyStyle($input, $output);

        if (null === $this->signatureProvider) {
            $this->emitDisabled($output, $io, $format);

            return Command::INVALID;
        }

        $total = 0;
        $signed = 0;
        $unsigned = 0;
        $tampered = [];

        foreach ($this->repository->streamAll() as $entry) {
            ++$total;

            $signature = $entry->getSignature();
            if (null === $signature) {
                ++$unsigned;

                continue;
            }

            ++$signed;

            $expected = $this->signatureProvider->sign(AuditEntrySignature::payloadFor($entry));
            if (hash_equals($expected, $signature)) {
                continue;
            }

            $id = (string) $entry->getId();
            $tampered[] = $id;
            $this->logger?->error('Audit trail tamper detected', [
                'entry_id' => $id,
                'entity_class' => $entry->getEntityClass(),
                'entity_id' => $entry->getEntityId(),
            ]);

            if ($failFast) {
                break;
            }
        }

        $this->emitReport($output, $io, $format, $total, $signed, $unsigned, $tampered);

        return [] === $tampered ? Command::SUCCESS : Command::FAILURE;
    }

    private function emitDisabled(OutputInterface $output, SymfonyStyle $io, string $format): void
    {
        $message = 'Integrity sealing is disabled. Enable "doctrine_audit_trail.integrity" to verify signatures.';

        if (self::FORMAT_JSON === $format) {
            $output->writeln(json_encode(['status' => 'disabled', 'error' => $message], \JSON_THROW_ON_ERROR));

            return;
        }

        $io->error($message);
    }

    /**
     * @param list<string> $tampered
     */
    private function emitReport(OutputInterface $output, SymfonyStyle $io, string $format, int $total, int $signed, int $unsigned, array $tampered): void
    {
        if (self::FORMAT_JSON === $format) {
            $output->writeln(json_encode([
                'status' => [] === $tampered ? 'ok' : 'tampered',
                'total' => $total,
                'signed' => $signed,
                'unsigned' => $unsigned,
                'tampered' => $tampered,
            ], \JSON_THROW_ON_ERROR));

            return;
        }

        $io->writeln(\sprintf('Scanned %d entries: %d signed, %d unsigned.', $total, $signed, $unsigned));

        if ([] !== $tampered) {
            $io->error(\sprintf('Tamper detected on %d entry(ies): #%s', \count($tampered), implode(', #', $tampered)));

            return;
        }

        $io->success('Audit trail integrity verified: no tampering detected.');
    }
}
