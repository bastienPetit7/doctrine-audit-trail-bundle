<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Persister;

use Metadev\AuditLogBundle\Entity\AuditLog;

interface AuditPersisterInterface
{
    /**
     * @param iterable<AuditLog> $auditLogs
     */
    public function persist(iterable $auditLogs): void;
}
