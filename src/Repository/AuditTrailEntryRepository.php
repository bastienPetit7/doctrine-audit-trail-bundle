<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;

/** @extends ServiceEntityRepository<AuditTrailEntry> */
class AuditTrailEntryRepository extends ServiceEntityRepository
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
}
