<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Diff;

use Metadev\DoctrineAuditTrailBundle\Diff\ChangeSetExtractor;
use Metadev\DoctrineAuditTrailBundle\Diff\DiffFormatterRegistry;
use Metadev\DoctrineAuditTrailBundle\Diff\Formatter\ScalarValueFormatter;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Metadata\AuditMetadata;
use Metadev\DoctrineAuditTrailBundle\Metadata\AuditMetadataFactory;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Entity\AuditedDummy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChangeSetExtractorTest extends TestCase
{
    private function extractor(): ChangeSetExtractor
    {
        return new ChangeSetExtractor(new DiffFormatterRegistry([new ScalarValueFormatter()]));
    }

    #[Test]
    public function it_should_produce_a_json_serialisable_diff_for_scalars_dates_and_enums(): void
    {
        $changeSet = [
            'title' => ['Old', 'New'],
            'publishedAt' => [null, new \DateTimeImmutable('2026-01-02T03:04:05+00:00')],
            'status' => [null, AuditAction::Create],
            'views' => [1, 2],
        ];

        $diff = $this->extractor()->extractChanges($changeSet, new AuditMetadata(auditable: true));

        self::assertSame(
            [
                'before' => ['title' => 'Old', 'publishedAt' => null, 'status' => null, 'views' => 1],
                'after' => ['title' => 'New', 'publishedAt' => '2026-01-02T03:04:05+00:00', 'status' => 'create', 'views' => 2],
            ],
            $diff,
        );
        self::assertJson(json_encode($diff, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_should_exclude_ignored_fields_from_the_diff(): void
    {
        $changeSet = [
            'title' => ['Old', 'New'],
            'password' => ['secret-a', 'secret-b'],
        ];

        $metadata = new AuditMetadata(auditable: true, ignoredFields: ['password' => true]);

        $diff = $this->extractor()->extractChanges($changeSet, $metadata);

        self::assertArrayHasKey('title', $diff['after']);
        self::assertArrayNotHasKey('password', $diff['after']);
        self::assertArrayNotHasKey('password', $diff['before']);
    }

    #[Test]
    public function it_should_not_record_a_blacklisted_field_in_the_diff_by_default(): void
    {
        $metadata = (new AuditMetadataFactory())->getMetadata(AuditedDummy::class);

        $changeSet = [
            'title' => ['Old', 'New'],
            'apiKey' => ['key-a', 'key-b'],
        ];

        $diff = $this->extractor()->extractChanges($changeSet, $metadata);

        self::assertArrayHasKey('title', $diff['after']);
        self::assertArrayNotHasKey('apiKey', $diff['after']);
        self::assertArrayNotHasKey('apiKey', $diff['before']);
    }

    #[Test]
    public function it_should_snapshot_the_before_state_for_deletions(): void
    {
        $metadata = new AuditMetadata(auditable: true, ignoredFields: ['password' => true]);

        $diff = $this->extractor()->extractDeletion(
            ['title' => 'Gone', 'password' => 'secret'],
            $metadata,
        );

        self::assertSame(['before' => ['title' => 'Gone'], 'after' => []], $diff);
    }
}
