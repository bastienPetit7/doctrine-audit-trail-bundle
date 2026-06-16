<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Entity;

use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditTrailEntryTest extends TestCase
{
    #[Test]
    public function it_should_detect_a_minimal_delete_snapshot(): void
    {
        $entry = new AuditTrailEntry(
            entityClass: 'App\\Entity\\Post',
            entityId: '42',
            entityLabel: 'Post',
            action: AuditAction::Delete,
            diff: ['before' => ['_snapshot_hash' => str_repeat('a', 64)], 'after' => []],
            userId: null,
            userIdentifier: null,
            ipAddress: null,
            userAgent: null,
            actorLabel: null,
        );

        self::assertTrue($entry->isMinimalDeleteSnapshot());
        self::assertSame(str_repeat('a', 64), $entry->getSnapshotHash());
    }

    #[Test]
    public function it_should_not_treat_a_full_delete_snapshot_as_minimal(): void
    {
        $entry = new AuditTrailEntry(
            entityClass: 'App\\Entity\\Post',
            entityId: '42',
            entityLabel: 'Post',
            action: AuditAction::Delete,
            diff: ['before' => ['title' => 'Gone'], 'after' => []],
            userId: null,
            userIdentifier: null,
            ipAddress: null,
            userAgent: null,
            actorLabel: null,
        );

        self::assertFalse($entry->isMinimalDeleteSnapshot());
        self::assertNull($entry->getSnapshotHash());
    }

    #[Test]
    public function it_should_not_treat_a_create_entry_as_a_minimal_delete_snapshot(): void
    {
        $entry = new AuditTrailEntry(
            entityClass: 'App\\Entity\\Post',
            entityId: '42',
            entityLabel: 'Post',
            action: AuditAction::Create,
            diff: ['before' => [], 'after' => ['title' => 'Hello']],
            userId: null,
            userIdentifier: null,
            ipAddress: null,
            userAgent: null,
            actorLabel: null,
        );

        self::assertFalse($entry->isMinimalDeleteSnapshot());
        self::assertNull($entry->getSnapshotHash());
    }
}
