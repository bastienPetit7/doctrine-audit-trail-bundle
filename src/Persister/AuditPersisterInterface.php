<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Persister;

use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;

interface AuditPersisterInterface
{
    /**
     * @param iterable<AuditTrailEntry> $auditLogs
     */
    public function persist(iterable $auditLogs): void;
}
