<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Command;

use Metadev\DoctrineAuditTrailBundle\Integrity\AuditEntrySignature;
use Metadev\DoctrineAuditTrailBundle\Integrity\SignatureProviderInterface;
use Metadev\DoctrineAuditTrailBundle\Repository\AuditTrailEntryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'audit:verify',
    description: 'Verifies the HMAC tamper-evidence seal of every audit trail entry.',
)]
final class VerifyAuditTrailCommand extends Command
{
    public function __construct(
        private readonly AuditTrailEntryRepository $repository,
        private readonly ?SignatureProviderInterface $signatureProvider = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->signatureProvider) {
            $io->error('Integrity sealing is disabled. Enable "doctrine_audit_trail.integrity" to verify signatures.');

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
            if (!hash_equals($expected, $signature)) {
                $tampered[] = (string) $entry->getId();
            }
        }

        $io->writeln(\sprintf('Scanned %d entries: %d signed, %d unsigned.', $total, $signed, $unsigned));

        if ([] !== $tampered) {
            $io->error(\sprintf('Tamper detected on %d entry(ies): #%s', \count($tampered), implode(', #', $tampered)));

            return Command::FAILURE;
        }

        $io->success('Audit trail integrity verified: no tampering detected.');

        return Command::SUCCESS;
    }
}
