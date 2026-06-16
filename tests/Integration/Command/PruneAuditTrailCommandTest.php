<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Integration\Command;

use Doctrine\ORM\EntityManagerInterface;
use Metadev\DoctrineAuditTrailBundle\Command\PruneAuditTrailCommand;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Repository\AuditTrailEntryRepository;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\InMemoryAuditEntityManagerTrait;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\StubManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneAuditTrailCommandTest extends TestCase
{
    use InMemoryAuditEntityManagerTrait;

    #[Test]
    public function it_should_delete_entries_strictly_older_than_the_cutoff(): void
    {
        $em = $this->createAuditEntityManager();
        $this->seed($em, '2020-01-01 00:00:00');
        $this->seed($em, '2024-01-01 00:00:00');

        $tester = $this->commandTester($em);
        $exit = $tester->execute(['--before' => '2022-01-01']);

        self::assertSame(Command::SUCCESS, $exit);
        $remaining = $this->repositoryFor($em)->findByEntity('App\\Entity\\Post', '1', limit: 100);
        self::assertCount(1, $remaining);
        self::assertSame('2024-01-01 00:00:00', $remaining[0]->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertStringContainsString('Pruned 1 entry', $tester->getDisplay());
    }

    #[Test]
    public function it_should_not_delete_anything_when_dry_run_is_set(): void
    {
        $em = $this->createAuditEntityManager();
        $this->seed($em, '2010-01-01 00:00:00');
        $this->seed($em, '2011-01-01 00:00:00');

        $tester = $this->commandTester($em);
        $exit = $tester->execute(['--before' => '2020-01-01', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertCount(2, $this->repositoryFor($em)->findByEntity('App\\Entity\\Post', '1', limit: 100));
        self::assertStringContainsString('Dry-run: 2 entry', $tester->getDisplay());
    }

    #[Test]
    public function it_should_use_the_default_age_when_before_is_omitted(): void
    {
        $em = $this->createAuditEntityManager();
        $this->seed($em, '2000-01-01 00:00:00');
        $this->seed($em, (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'));

        $tester = $this->commandTester($em, defaultAge: '-5 years');
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        $remaining = $this->repositoryFor($em)->findByEntity('App\\Entity\\Post', '1', limit: 100);
        self::assertCount(1, $remaining);
    }

    #[Test]
    public function it_should_fail_when_neither_before_nor_default_age_is_set(): void
    {
        $em = $this->createAuditEntityManager();
        $tester = $this->commandTester($em);

        $exit = $tester->execute([]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('Missing cutoff', $tester->getDisplay());
    }

    #[Test]
    public function it_should_fail_when_before_is_not_parseable(): void
    {
        $em = $this->createAuditEntityManager();
        $tester = $this->commandTester($em);

        $exit = $tester->execute(['--before' => 'not-a-date']);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('Invalid --before value', $tester->getDisplay());
    }

    #[Test]
    public function it_should_reject_a_whitespace_only_before(): void
    {
        $em = $this->createAuditEntityManager();
        $tester = $this->commandTester($em);

        $exit = $tester->execute(['--before' => '   ']);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('Missing cutoff', $tester->getDisplay());
    }

    #[Test]
    public function it_should_refuse_a_cutoff_that_is_not_strictly_in_the_past(): void
    {
        $em = $this->createAuditEntityManager();
        $this->seed($em, '2010-01-01 00:00:00');

        $tester = $this->commandTester($em);
        $exit = $tester->execute(['--before' => 'now']);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('Refusing to prune', $tester->getDisplay());
        self::assertCount(1, $this->repositoryFor($em)->findByEntity('App\\Entity\\Post', '1', limit: 100));
    }

    #[Test]
    public function it_should_log_the_pruning_summary(): void
    {
        $em = $this->createAuditEntityManager();
        $this->seed($em, '2010-01-01 00:00:00');
        $this->seed($em, '2011-01-01 00:00:00');

        $logger = $this->spyLogger();
        $tester = $this->commandTester($em, logger: $logger);
        $tester->execute(['--before' => '2020-01-01']);

        self::assertSame(['info'], $logger->levels);
        self::assertSame('audit.prune.completed', $logger->messages[0]);
        self::assertSame(2, $logger->contexts[0]['deleted']);
        self::assertFalse($logger->contexts[0]['dry_run']);
        self::assertArrayHasKey('cutoff', $logger->contexts[0]);
        self::assertArrayHasKey('duration_ms', $logger->contexts[0]);
    }

    #[Test]
    public function it_should_log_rejection_when_input_is_invalid(): void
    {
        $em = $this->createAuditEntityManager();
        $logger = $this->spyLogger();
        $tester = $this->commandTester($em, logger: $logger);

        $tester->execute(['--before' => 'now']);

        self::assertSame(['warning'], $logger->levels);
        self::assertSame('audit.prune.rejected', $logger->messages[0]);
        self::assertSame('cutoff_not_in_past', $logger->contexts[0]['reason']);
    }

    #[Test]
    public function it_should_delete_in_batches_until_drained(): void
    {
        $em = $this->createAuditEntityManager();
        for ($i = 0; $i < 5; ++$i) {
            $this->seed($em, '2010-01-0'.($i + 1).' 00:00:00');
        }

        $tester = $this->commandTester($em);
        $exit = $tester->execute(['--before' => '2020-01-01', '--batch' => '2']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertCount(0, $this->repositoryFor($em)->findByEntity('App\\Entity\\Post', '1', limit: 100));
        self::assertStringContainsString('Pruned 5 entry', $tester->getDisplay());
    }

    private function commandTester(
        EntityManagerInterface $em,
        ?string $defaultAge = null,
        ?AbstractLogger $logger = null,
    ): CommandTester {
        return new CommandTester(new PruneAuditTrailCommand($this->repositoryFor($em), $defaultAge, $logger));
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

    private function seed(EntityManagerInterface $em, string $createdAt): void
    {
        $entry = new AuditTrailEntry(
            entityClass: 'App\\Entity\\Post',
            entityId: '1',
            entityLabel: null,
            action: AuditAction::Update,
            diff: ['before' => [], 'after' => ['title' => 'v']],
            userId: '1',
            userIdentifier: 'admin',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
            actorLabel: 'admin',
            createdAt: new \DateTimeImmutable($createdAt),
        );

        $em->persist($entry);
        $em->flush();
    }
}
