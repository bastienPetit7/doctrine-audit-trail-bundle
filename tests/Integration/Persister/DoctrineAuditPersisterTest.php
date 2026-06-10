<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Integration\Persister;

use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Persister\DoctrineAuditPersister;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\InMemoryAuditEntityManagerTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DoctrineAuditPersisterTest extends TestCase
{
    use InMemoryAuditEntityManagerTrait;

    #[Test]
    public function it_should_write_entries_to_the_audit_entity_manager(): void
    {
        $entityManager = $this->createAuditEntityManager();
        $persister = new DoctrineAuditPersister($entityManager);

        $persister->persist([$this->makeEntry('a'), $this->makeEntry('b')]);

        $entityManager->clear();
        /** @var AuditTrailEntry[] $rows */
        $rows = $entityManager
            ->createQuery('SELECT a FROM '.AuditTrailEntry::class.' a ORDER BY a.id ASC')
            ->getResult();

        self::assertCount(2, $rows);
        self::assertSame('a', $rows[0]->getEntityId());
        self::assertSame(AuditAction::Create, $rows[0]->getAction());
    }

    #[Test]
    public function it_should_not_flush_when_there_is_nothing_to_persist(): void
    {
        $entityManager = $this->createAuditEntityManager();
        $persister = new DoctrineAuditPersister($entityManager);

        $persister->persist([]);

        $count = (int) $entityManager
            ->createQuery('SELECT COUNT(a.id) FROM '.AuditTrailEntry::class.' a')
            ->getSingleScalarResult();

        self::assertSame(0, $count);
    }

    private function makeEntry(string $id): AuditTrailEntry
    {
        return new AuditTrailEntry(
            entityClass: 'App\\Entity\\Post',
            entityId: $id,
            entityLabel: 'Post',
            action: AuditAction::Create,
            diff: ['before' => [], 'after' => ['title' => 'Hello']],
            userId: '1',
            userIdentifier: 'admin',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
            actorLabel: 'admin',
        );
    }
}
