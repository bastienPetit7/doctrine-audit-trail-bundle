<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Integration\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Repository\AuditTrailEntryRepository;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\InMemoryAuditEntityManagerTrait;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\StubManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditTrailEntryRepositoryTest extends TestCase
{
    use InMemoryAuditEntityManagerTrait;

    #[Test]
    public function it_should_return_entries_for_an_entity_newest_first(): void
    {
        $entityManager = $this->createAuditEntityManager();
        $this->seedEntries($entityManager, entityId: '7', count: 3);

        $rows = $this->repositoryFor($entityManager)->findByEntity('App\\Entity\\Post', '7');

        self::assertCount(3, $rows);
        self::assertGreaterThan($rows[1]->getId(), $rows[0]->getId());
    }

    #[Test]
    public function it_should_cap_findByEntity_results_to_the_requested_limit(): void
    {
        $entityManager = $this->createAuditEntityManager();
        $this->seedEntries($entityManager, entityId: '7', count: 5);

        $rows = $this->repositoryFor($entityManager)->findByEntity('App\\Entity\\Post', '7', limit: 2);

        self::assertCount(2, $rows);
    }

    #[Test]
    public function it_should_paginate_findByEntity_with_a_before_id_cursor(): void
    {
        $entityManager = $this->createAuditEntityManager();
        $this->seedEntries($entityManager, entityId: '7', count: 4);

        $repository = $this->repositoryFor($entityManager);
        $firstPage = $repository->findByEntity('App\\Entity\\Post', '7', limit: 2);
        $secondPage = $repository->findByEntity('App\\Entity\\Post', '7', limit: 2, beforeId: $firstPage[1]->getId());

        self::assertCount(2, $secondPage);
        self::assertLessThan($firstPage[1]->getId(), $secondPage[0]->getId());
    }

    #[Test]
    public function it_should_cap_findByActor_results_to_the_requested_limit(): void
    {
        $entityManager = $this->createAuditEntityManager();
        $this->seedEntries($entityManager, entityId: '1', count: 3, userIdentifier: 'jane');
        $this->seedEntries($entityManager, entityId: '2', count: 2, userIdentifier: 'jane');

        $rows = $this->repositoryFor($entityManager)->findByActor('jane', limit: 2);

        self::assertCount(2, $rows);
        self::assertSame('jane', $rows[0]->getUserIdentifier());
    }

    #[Test]
    public function it_should_count_entries_older_than_a_cutoff(): void
    {
        $em = $this->createAuditEntityManager();
        $this->seedAt($em, '2020-01-01 00:00:00');
        $this->seedAt($em, '2020-06-01 00:00:00');
        $this->seedAt($em, '2024-01-01 00:00:00');

        $count = $this->repositoryFor($em)->countOlderThan(new \DateTimeImmutable('2022-01-01'));

        self::assertSame(2, $count);
    }

    #[Test]
    public function it_should_prune_entries_in_bounded_batches(): void
    {
        $em = $this->createAuditEntityManager();
        for ($i = 1; $i <= 5; ++$i) {
            $this->seedAt($em, \sprintf('2010-01-0%d 00:00:00', $i));
        }

        $repository = $this->repositoryFor($em);

        self::assertSame(2, $repository->pruneOlderThan(new \DateTimeImmutable('2020-01-01'), 2));
        self::assertSame(2, $repository->pruneOlderThan(new \DateTimeImmutable('2020-01-01'), 2));
        self::assertSame(1, $repository->pruneOlderThan(new \DateTimeImmutable('2020-01-01'), 2));
        self::assertSame(0, $repository->pruneOlderThan(new \DateTimeImmutable('2020-01-01'), 2));
    }

    #[Test]
    public function it_should_reject_a_non_positive_batch_size(): void
    {
        $em = $this->createAuditEntityManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->repositoryFor($em)->pruneOlderThan(new \DateTimeImmutable('2020-01-01'), 0);
    }

    private function seedAt(EntityManagerInterface $em, string $createdAt): void
    {
        $em->persist(new AuditTrailEntry(
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
        ));
        $em->flush();
    }

    private function repositoryFor(EntityManagerInterface $entityManager): AuditTrailEntryRepository
    {
        return new AuditTrailEntryRepository(new StubManagerRegistry($entityManager));
    }

    private function seedEntries(
        EntityManagerInterface $entityManager,
        string $entityId,
        int $count,
        string $userIdentifier = 'admin',
    ): void {
        for ($i = 0; $i < $count; ++$i) {
            $entityManager->persist(new AuditTrailEntry(
                entityClass: 'App\\Entity\\Post',
                entityId: $entityId,
                entityLabel: null,
                action: AuditAction::Update,
                diff: ['before' => [], 'after' => ['title' => 'v'.$i]],
                userId: '1',
                userIdentifier: $userIdentifier,
                ipAddress: '127.0.0.1',
                userAgent: 'PHPUnit',
                actorLabel: $userIdentifier,
            ));
        }

        $entityManager->flush();
    }
}
