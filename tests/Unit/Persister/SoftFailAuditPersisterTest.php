<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Persister;

use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Persister\AuditPersisterInterface;
use Metadev\DoctrineAuditTrailBundle\Persister\Exception\AuditDispatchFailedException;
use Metadev\DoctrineAuditTrailBundle\Persister\SoftFailAuditPersister;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\AuditTrailEntryBuilder;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * @internal
 */
#[Small]
final class SoftFailAuditPersisterTest extends TestCase
{
    #[Test]
    public function it_should_delegate_to_the_inner_persister(): void
    {
        $inner = new class implements AuditPersisterInterface {
            /** @var iterable<AuditTrailEntry>|null */
            public ?iterable $received = null;

            public function persist(iterable $auditLogs): void
            {
                $this->received = $auditLogs;
            }
        };

        $entries = [AuditTrailEntryBuilder::make()];
        (new SoftFailAuditPersister($inner))->persist($entries);

        self::assertSame($entries, $inner->received);
    }

    #[Test]
    public function it_should_swallow_and_log_when_the_inner_persister_throws(): void
    {
        $inner = new class implements AuditPersisterInterface {
            public function persist(iterable $auditLogs): void
            {
                throw new \RuntimeException('audit db down');
            }
        };

        $logger = $this->spyLogger();

        (new SoftFailAuditPersister($inner, $logger))->persist([
            AuditTrailEntryBuilder::make('1'),
            AuditTrailEntryBuilder::make('2'),
        ]);

        self::assertSame(['error'], $logger->levels);
        self::assertCount(1, $logger->contexts);
        self::assertSame(2, $logger->contexts[0]['dropped_entries']);
        self::assertSame(2, $logger->contexts[0]['total_entries']);
        self::assertInstanceOf(\RuntimeException::class, $logger->contexts[0]['exception']);
    }

    #[Test]
    public function it_should_report_partial_loss_from_a_dispatch_failure(): void
    {
        $inner = new class implements AuditPersisterInterface {
            public function persist(iterable $auditLogs): void
            {
                throw new AuditDispatchFailedException(2, 5, new \RuntimeException('broker down'));
            }
        };

        $logger = $this->spyLogger();

        (new SoftFailAuditPersister($inner, $logger))->persist(array_map(
            static fn () => AuditTrailEntryBuilder::make(),
            range(1, 5),
        ));

        self::assertSame(2, $logger->contexts[0]['dropped_entries']);
        self::assertSame(5, $logger->contexts[0]['total_entries']);
    }

    #[Test]
    public function it_should_rethrow_programming_errors(): void
    {
        $inner = new class implements AuditPersisterInterface {
            public function persist(iterable $auditLogs): void
            {
                throw new \TypeError('malformed entry');
            }
        };

        $this->expectException(\TypeError::class);

        (new SoftFailAuditPersister($inner))->persist([AuditTrailEntryBuilder::make()]);
    }

    /**
     * @return AbstractLogger&object{levels: list<string>, contexts: list<array<string, mixed>>}
     */
    private function spyLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];
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
                $this->contexts[] = $context;
            }
        };
    }
}
