<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Integration\Messenger;

use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Messenger\PersistAuditTrailEntries;
use Metadev\DoctrineAuditTrailBundle\Messenger\PersistAuditTrailEntriesHandler;
use Metadev\DoctrineAuditTrailBundle\Persister\DoctrineAuditPersister;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\AuditTrailEntryBuilder;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\InMemoryAuditEntityManagerTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PersistAuditTrailEntriesHandlerTest extends TestCase
{
    use InMemoryAuditEntityManagerTrait;

    #[Test]
    public function it_should_persist_the_message_entries_to_the_audit_entity_manager(): void
    {
        $entityManager = $this->createAuditEntityManager();
        $handler = new PersistAuditTrailEntriesHandler(new DoctrineAuditPersister($entityManager));

        $handler(new PersistAuditTrailEntries([
            AuditTrailEntryBuilder::make('1'),
            AuditTrailEntryBuilder::make('2'),
        ]));

        $entityManager->clear();
        $count = (int) $entityManager
            ->createQuery('SELECT COUNT(a.id) FROM '.AuditTrailEntry::class.' a')
            ->getSingleScalarResult();

        self::assertSame(2, $count);
    }
}
