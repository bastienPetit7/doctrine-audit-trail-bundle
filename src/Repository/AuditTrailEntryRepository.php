<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;

/** @extends ServiceEntityRepository<AuditTrailEntry> */
final class AuditTrailEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditTrailEntry::class);
    }

    /**
     * @return AuditTrailEntry[]
     */
    public function findByEntity(string $entityClass, int|string $entityId): array
    {
        return $this->findBy(
            ['entityClass' => $entityClass, 'entityId' => (string) $entityId],
            ['createdAt' => 'DESC'],
        );
    }

    /**
     * @return AuditTrailEntry[]
     */
    public function findByActor(string $userIdentifier, int $limit = 50): array
    {
        return $this->findBy(
            ['userIdentifier' => $userIdentifier],
            ['createdAt' => 'DESC'],
            $limit,
        );
    }

    /**
     * Streams every entry in insertion order, one at a time, so integrity
     * verification stays memory-bounded on large tables.
     *
     * @return iterable<AuditTrailEntry>
     */
    public function streamAll(): iterable
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->toIterable();
    }
}
