<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Integration\Command;

use Doctrine\ORM\EntityManagerInterface;
use Metadev\DoctrineAuditTrailBundle\Command\ActorAnonymiseAuditTrailCommand;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Factory\AuditTrailEntryFactory;
use Metadev\DoctrineAuditTrailBundle\Integrity\AuditEntrySignature;
use Metadev\DoctrineAuditTrailBundle\Integrity\HmacSignatureProvider;
use Metadev\DoctrineAuditTrailBundle\Integrity\SignatureProviderInterface;
use Metadev\DoctrineAuditTrailBundle\Repository\AuditTrailEntryRepository;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Entity\AuditedDummy;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\InMemoryAuditEntityManagerTrait;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\StubManagerRegistry;
use Metadev\DoctrineAuditTrailBundle\User\AuditActor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ActorAnonymiseAuditTrailCommandTest extends TestCase
{
    use InMemoryAuditEntityManagerTrait;

    private const TARGET_USER = 'jane';
    private const OTHER_USER = 'mallory';

    #[Test]
    public function it_should_anonymise_every_row_attributed_to_the_target_actor(): void
    {
        $em = $this->createAuditEntityManager();
        $this->seedUnsigned($em, self::TARGET_USER);
        $this->seedUnsigned($em, self::TARGET_USER);
        $otherEntryId = $this->seedUnsigned($em, self::OTHER_USER);

        $tester = $this->commandTester($em);
        $exit = $tester->execute(['--user-identifier' => self::TARGET_USER, '--reason' => 'GDPR-art-17']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Anonymised 2 entry', $tester->getDisplay());

        $em->clear();
        $expectedHash = 'gdpr-'.hash('sha256', self::TARGET_USER);
        $rows = $this->repositoryFor($em)->findByActor($expectedHash, limit: 100);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($expectedHash, $row->getUserIdentifier());
            self::assertNull($row->getIpAddress());
            self::assertNull($row->getUserAgent());
            self::assertSame('gdpr-anonymised', $row->getActorLabel());
            self::assertTrue($row->isActorAnonymised());
        }

        // Untouched neighbour entry must remain strictly intact.
        $other = $em->find(AuditTrailEntry::class, $otherEntryId);
        self::assertNotNull($other);
        self::assertSame(self::OTHER_USER, $other->getUserIdentifier());
        self::assertFalse($other->isActorAnonymised());
    }

    #[Test]
    public function it_should_recompute_the_signature_so_verify_keeps_passing(): void
    {
        $em = $this->createAuditEntityManager();
        $provider = new HmacSignatureProvider('integration-secret-a1b2c3d4e5f60718');
        $this->seedSigned($em, $provider, self::TARGET_USER);

        $tester = $this->commandTester($em, signatureProvider: $provider);
        $tester->execute(['--user-identifier' => self::TARGET_USER, '--reason' => 'GDPR-art-17']);

        $em->clear();
        $entry = $this->repositoryFor($em)->findByActor('gdpr-'.hash('sha256', self::TARGET_USER), limit: 1)[0];

        self::assertTrue(
            hash_equals(
                $provider->sign(AuditEntrySignature::payloadFor($entry)),
                (string) $entry->getSignature(),
            ),
            'Signature must remain valid after anonymisation.',
        );
    }

    #[Test]
    public function it_should_not_touch_anything_when_dry_run_is_set(): void
    {
        $em = $this->createAuditEntityManager();
        $this->seedUnsigned($em, self::TARGET_USER);
        $this->seedUnsigned($em, self::TARGET_USER);

        $tester = $this->commandTester($em);
        $exit = $tester->execute([
            '--user-identifier' => self::TARGET_USER,
            '--reason' => 'GDPR-art-17',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Dry-run: 2 entry', $tester->getDisplay());
        self::assertCount(2, $this->repositoryFor($em)->findByActor(self::TARGET_USER, limit: 100));
    }

    #[Test]
    public function it_should_be_idempotent_when_rerun_on_the_same_actor(): void
    {
        $em = $this->createAuditEntityManager();
        $this->seedUnsigned($em, self::TARGET_USER);

        $tester = $this->commandTester($em);
        $tester->execute(['--user-identifier' => self::TARGET_USER, '--reason' => 'GDPR-art-17']);
        // Second run: nothing to do, the original identifier no longer matches.
        $tester->execute(['--user-identifier' => self::TARGET_USER, '--reason' => 'GDPR-art-17']);

        self::assertStringContainsString('Anonymised 0 entry', $tester->getDisplay());
    }

    #[Test]
    public function it_should_drain_all_entries_when_batch_is_smaller_than_total(): void
    {
        $em = $this->createAuditEntityManager();
        for ($i = 0; $i < 5; ++$i) {
            $this->seedUnsigned($em, self::TARGET_USER);
        }

        $tester = $this->commandTester($em);
        $tester->execute(['--user-identifier' => self::TARGET_USER, '--reason' => 'GDPR-art-17', '--batch' => '2']);

        self::assertStringContainsString('Anonymised 5 entry', $tester->getDisplay());
        self::assertSame(0, $this->repositoryFor($em)->countActorAnonymisableForActor(self::TARGET_USER));
    }

    #[Test]
    public function it_should_fail_when_user_identifier_is_missing(): void
    {
        $em = $this->createAuditEntityManager();
        $tester = $this->commandTester($em);

        $exit = $tester->execute(['--reason' => 'GDPR-art-17']);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('Missing --user-identifier', $tester->getDisplay());
    }

    #[Test]
    public function it_should_fail_when_reason_is_missing(): void
    {
        $em = $this->createAuditEntityManager();
        $tester = $this->commandTester($em);

        $exit = $tester->execute(['--user-identifier' => self::TARGET_USER]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('Missing --reason', $tester->getDisplay());
    }

    #[Test]
    public function it_should_reject_a_whitespace_only_user_identifier(): void
    {
        $em = $this->createAuditEntityManager();
        $tester = $this->commandTester($em);

        $exit = $tester->execute(['--user-identifier' => '   ', '--reason' => 'GDPR-art-17']);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('Missing --user-identifier', $tester->getDisplay());
    }

    #[Test]
    public function it_should_log_the_completion_summary_without_leaking_the_identifier(): void
    {
        $em = $this->createAuditEntityManager();
        $this->seedUnsigned($em, self::TARGET_USER);
        $this->seedUnsigned($em, self::TARGET_USER);

        $logger = $this->spyLogger();
        $tester = $this->commandTester($em, logger: $logger);
        $tester->execute(['--user-identifier' => self::TARGET_USER, '--reason' => 'GDPR-art-17']);

        self::assertSame(['info'], $logger->levels);
        self::assertSame('audit.actor_anonymise.completed', $logger->messages[0]);
        self::assertSame(2, $logger->contexts[0]['anonymised']);
        self::assertSame('GDPR-art-17', $logger->contexts[0]['reason']);
        self::assertSame('gdpr-'.hash('sha256', self::TARGET_USER), $logger->contexts[0]['user_identifier_hash']);
        self::assertArrayNotHasKey('user_identifier', $logger->contexts[0]);
        self::assertFalse($logger->contexts[0]['dry_run']);
        self::assertArrayHasKey('duration_ms', $logger->contexts[0]);
    }

    #[Test]
    public function it_should_log_rejection_when_validation_fails(): void
    {
        $em = $this->createAuditEntityManager();
        $logger = $this->spyLogger();
        $tester = $this->commandTester($em, logger: $logger);

        $tester->execute(['--reason' => 'GDPR-art-17']);

        self::assertSame(['warning'], $logger->levels);
        self::assertSame('audit.actor_anonymise.rejected', $logger->messages[0]);
        self::assertSame('missing_user_identifier', $logger->contexts[0]['reason']);
    }

    private function commandTester(
        EntityManagerInterface $em,
        ?SignatureProviderInterface $signatureProvider = null,
        ?AbstractLogger $logger = null,
    ): CommandTester {
        return new CommandTester(new ActorAnonymiseAuditTrailCommand(
            $this->repositoryFor($em),
            $signatureProvider,
            $logger,
        ));
    }

    /**
     * @return AbstractLogger&object{levels: list<string>, messages: list<string>, contexts: list<array<string, mixed>>}
     */
    private function spyLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];
            /** @var list<string> */
            public array $messages = [];
            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            /**
             * @param array<string, mixed> $context
             *
             * @phpstan-param mixed $level
             * @phpstan-param mixed $message
             */
            public function log($level, $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
                $this->messages[] = (string) $message;
                $this->contexts[] = $context;
            }
        };
    }

    private function repositoryFor(EntityManagerInterface $em): AuditTrailEntryRepository
    {
        return new AuditTrailEntryRepository(new StubManagerRegistry($em));
    }

    private function seedUnsigned(EntityManagerInterface $em, string $userIdentifier): int
    {
        $entry = new AuditTrailEntry(
            entityClass: 'App\\Entity\\Post',
            entityId: '1',
            entityLabel: null,
            action: AuditAction::Update,
            diff: ['before' => [], 'after' => ['title' => 'v']],
            userId: '7',
            userIdentifier: $userIdentifier,
            ipAddress: '203.0.113.7',
            userAgent: 'PHPUnit',
            actorLabel: $userIdentifier,
        );

        $em->persist($entry);
        $em->flush();

        return (int) $entry->getId();
    }

    private function seedSigned(EntityManagerInterface $em, HmacSignatureProvider $provider, string $userIdentifier): int
    {
        $entry = (new AuditTrailEntryFactory($provider))->create(
            new AuditedDummy(),
            AuditAction::Update,
            ['before' => ['title' => 'before'], 'after' => ['title' => 'after']],
            new AuditActor(label: $userIdentifier, userIdentifier: $userIdentifier, userId: '7', ipAddress: '203.0.113.7', userAgent: 'PHPUnit'),
            ['id' => 1],
        );

        $em->persist($entry);
        $em->flush();

        return (int) $entry->getId();
    }
}
