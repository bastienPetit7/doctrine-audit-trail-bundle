<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Metadev\AuditLogBundle\Entity\AuditLog;

/** @extends ServiceEntityRepository<AuditLog> */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return AuditLog[]
     */
    public function findByEntity(string $entityClass, int|string $entityId): array
    {
        return $this->findBy(
            ['entityClass' => $entityClass, 'entityId' => (string) $entityId],
            ['createdAt' => 'DESC'],
        );
    }

    /**
     * @return AuditLog[]
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
