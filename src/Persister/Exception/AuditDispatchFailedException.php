<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Persister\Exception;

final class AuditDispatchFailedException extends \RuntimeException
{
    public function __construct(
        public readonly int $failedEntries,
        public readonly int $totalEntries,
        \Throwable $previous,
    ) {
        parent::__construct(
            \sprintf('Audit dispatch failed for %d of %d entries.', $failedEntries, $totalEntries),
            0,
            $previous,
        );
    }
}
