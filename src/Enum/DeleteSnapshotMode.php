<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Enum;

enum DeleteSnapshotMode: string
{
    /**
     * Stores only a SHA-256 fingerprint of the blacklisted snapshot.
     * Default — protects against accidental leakage of sensitive fields
     * that were never declared as ignored.
     */
    case Minimal = 'minimal';

    /**
     * Stores every field of the deleted entity (minus the blacklist).
     * Opt-in: requires explicit configuration.
     */
    case Full = 'full';
}
