<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Persister;

use Metadev\DoctrineAuditTrailBundle\Persister\Exception\AuditDispatchFailedException;
use Psr\Log\LoggerInterface;

final class SoftFailAuditPersister implements AuditPersisterInterface
{
    public function __construct(
        private readonly AuditPersisterInterface $inner,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function persist(iterable $auditLogs): void
    {
        $entries = $auditLogs instanceof \Traversable
            ? iterator_to_array($auditLogs, false)
            : array_values($auditLogs);

        try {
            $this->inner->persist($entries);
        } catch (\Exception $exception) {
            $total = \count($entries);
            $dropped = $exception instanceof AuditDispatchFailedException
                ? $exception->failedEntries
                : $total;

            $this->logger?->error(
                'Audit trail persistence failed; entries were dropped (soft_fail enabled).',
                [
                    'exception' => $exception,
                    'dropped_entries' => $dropped,
                    'total_entries' => $total,
                ],
            );
        }
    }
}
