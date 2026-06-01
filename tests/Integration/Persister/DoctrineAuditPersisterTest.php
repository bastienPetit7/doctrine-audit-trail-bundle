<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Tests\Integration\Persister;

use Metadev\AuditLogBundle\Entity\AuditLog;
use Metadev\AuditLogBundle\Enum\AuditAction;
use Metadev\AuditLogBundle\Persister\DoctrineAuditPersister;
use Metadev\AuditLogBundle\Tests\Integration\InMemoryAuditEntityManagerTrait;
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

        $persister->persist([$this->makeLog('a'), $this->makeLog('b')]);

        $entityManager->clear();
        /** @var AuditLog[] $rows */
        $rows = $entityManager
            ->createQuery('SELECT a FROM '.AuditLog::class.' a ORDER BY a.id ASC')
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
            ->createQuery('SELECT COUNT(a.id) FROM '.AuditLog::class.' a')
            ->getSingleScalarResult();

        self::assertSame(0, $count);
    }

    private function makeLog(string $id): AuditLog
    {
        return new AuditLog(
            entityClass: 'App\\Entity\\Post',
            entityId: $id,
            action: AuditAction::Create,
            diff: ['after' => ['title' => 'Hello']],
            userId: '1',
            userIdentifier: 'admin',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
            actorLabel: 'admin',
        );
    }
}
