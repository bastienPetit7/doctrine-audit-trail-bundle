<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Persister;

use Doctrine\ORM\EntityManagerInterface;

final class DoctrineAuditPersister implements AuditPersisterInterface
{
    public function __construct(
        private readonly EntityManagerInterface $auditEntityManager,
    ) {
    }

    public function persist(iterable $auditLogs): void
    {
        $hasEntries = false;

        foreach ($auditLogs as $auditLog) {
            $this->auditEntityManager->persist($auditLog);
            $hasEntries = true;
        }

        if ($hasEntries) {
            $this->auditEntityManager->flush();
        }
    }
}
