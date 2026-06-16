<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;

/** @extends ServiceEntityRepository<AuditTrailEntry> */
final class AuditTrailEntryRepository extends ServiceEntityRepository
{
    public const MAX_PAGE_SIZE = 1000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditTrailEntry::class);
    }

    /**
     * @return AuditTrailEntry[]
     */
    public function findByEntity(string $entityClass, int|string $entityId, int $limit = 50, ?int $beforeId = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.entityClass = :class')
            ->andWhere('e.entityId = :id')
            ->setParameter('class', $entityClass)
            ->setParameter('id', (string) $entityId)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(self::cappedLimit($limit));

        if (null !== $beforeId) {
            $qb->andWhere('e.id < :beforeId')->setParameter('beforeId', $beforeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return AuditTrailEntry[]
     */
    public function findByActor(string $userIdentifier, int $limit = 50, ?int $beforeId = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.userIdentifier = :uid')
            ->setParameter('uid', $userIdentifier)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(self::cappedLimit($limit));

        if (null !== $beforeId) {
            $qb->andWhere('e.id < :beforeId')->setParameter('beforeId', $beforeId);
        }

        return $qb->getQuery()->getResult();
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

    public function countOlderThan(\DateTimeImmutable $cutoff): int
    {
        $count = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function pruneOlderThan(\DateTimeImmutable $cutoff, int $batchSize): int
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException(\sprintf('Batch size must be >= 1, got %d.', $batchSize));
        }

        $ids = $this->createQueryBuilder('e')
            ->select('e.id')
            ->where('e.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults($batchSize)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $ids) {
            return 0;
        }

        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    public static function cappedLimit(int $limit): int
    {
        if ($limit < 1) {
            return 1;
        }

        return min($limit, self::MAX_PAGE_SIZE);
    }
}
