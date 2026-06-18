<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Diff;

use Metadev\DoctrineAuditTrailBundle\Diff\DeleteSnapshotPolicy;
use Metadev\DoctrineAuditTrailBundle\Diff\TruncationMarker;
use Metadev\DoctrineAuditTrailBundle\Enum\DeleteSnapshotMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeleteSnapshotPolicyTest extends TestCase
{
    #[Test]
    public function it_should_pass_the_diff_through_in_full_mode(): void
    {
        $diff = ['before' => ['title' => 'Gone', 'price' => 100], 'after' => []];

        self::assertSame($diff, (new DeleteSnapshotPolicy(DeleteSnapshotMode::Full))->apply($diff));
    }

    #[Test]
    public function it_should_replace_before_with_a_snapshot_hash_in_minimal_mode(): void
    {
        $diff = ['before' => ['title' => 'Gone', 'price' => 100], 'after' => []];

        $result = (new DeleteSnapshotPolicy(DeleteSnapshotMode::Minimal))->apply($diff);

        self::assertSame([], $result['after']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['before']['_snapshot_hash']);
        self::assertArrayNotHasKey('title', $result['before']);
        self::assertArrayNotHasKey('price', $result['before']);
    }

    #[Test]
    public function it_should_produce_a_deterministic_hash_regardless_of_field_order(): void
    {
        $policy = new DeleteSnapshotPolicy(DeleteSnapshotMode::Minimal);

        $resultA = $policy->apply(['before' => ['title' => 'Gone', 'price' => 100], 'after' => []]);
        $resultB = $policy->apply(['before' => ['price' => 100, 'title' => 'Gone'], 'after' => []]);

        self::assertSame($resultA['before']['_snapshot_hash'], $resultB['before']['_snapshot_hash']);
    }

    #[Test]
    public function it_should_emit_an_encoding_failed_marker_when_the_before_state_is_not_encodable(): void
    {
        $result = (new DeleteSnapshotPolicy(DeleteSnapshotMode::Minimal))
            ->apply(['before' => ['ratio' => \NAN], 'after' => []]);

        self::assertSame([], $result['before']);
        self::assertTrue($result['after']['_truncated']);
        self::assertSame(TruncationMarker::REASON_ENCODING_FAILED, $result['after']['_reason']);
    }
}
