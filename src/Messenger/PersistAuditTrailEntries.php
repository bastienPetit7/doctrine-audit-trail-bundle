<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Messenger;

use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;

final class PersistAuditTrailEntries
{
    /**
     * @param list<AuditTrailEntry> $entries
     */
    public function __construct(
        public readonly array $entries,
    ) {
    }
}
