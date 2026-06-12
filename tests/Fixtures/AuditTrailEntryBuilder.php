<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Fixtures;

use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;

final class AuditTrailEntryBuilder
{
    public static function make(string $entityId = '1'): AuditTrailEntry
    {
        return new AuditTrailEntry(
            entityClass: 'App\\Entity\\Post',
            entityId: $entityId,
            entityLabel: 'Post',
            action: AuditAction::Create,
            diff: ['before' => [], 'after' => ['title' => 'Hello']],
            userId: '1',
            userIdentifier: 'admin',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
            actorLabel: 'admin',
        );
    }
}
