<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Diff;

use Metadev\DoctrineAuditTrailBundle\Diff\DiffSizeGuard;
use Metadev\DoctrineAuditTrailBundle\Diff\TruncationMarker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DiffSizeGuardTest extends TestCase
{
    #[Test]
    public function it_should_pass_through_a_diff_under_the_limit(): void
    {
        $diff = ['before' => ['title' => 'old'], 'after' => ['title' => 'new']];

        self::assertSame($diff, (new DiffSizeGuard(maxSizeBytes: 1024))->apply($diff));
    }

    #[Test]
    public function it_should_replace_an_oversized_diff_with_a_size_exceeded_marker(): void
    {
        $diff = ['before' => [], 'after' => ['payload' => str_repeat('A', 2000)]];

        $result = (new DiffSizeGuard(maxSizeBytes: 256))->apply($diff);

        self::assertSame([], $result['before']);
        self::assertTrue($result['after']['_truncated']);
        self::assertSame(TruncationMarker::REASON_SIZE_EXCEEDED, $result['after']['_reason']);
        self::assertGreaterThan(256, $result['after']['_originalSize']);
    }

    #[Test]
    public function it_should_emit_an_encoding_failed_marker_when_the_diff_is_not_json_serialisable(): void
    {
        $diff = ['before' => [], 'after' => ['ratio' => \NAN]];

        $result = (new DiffSizeGuard())->apply($diff);

        self::assertSame([], $result['before']);
        self::assertTrue($result['after']['_truncated']);
        self::assertSame(TruncationMarker::REASON_ENCODING_FAILED, $result['after']['_reason']);
    }

    #[Test]
    public function it_should_skip_the_check_entirely_when_no_size_limit_is_configured(): void
    {
        $diff = ['before' => [], 'after' => ['payload' => str_repeat('A', 10_000)]];

        self::assertSame($diff, (new DiffSizeGuard(DiffSizeGuard::NO_SIZE_LIMIT))->apply($diff));
    }
}
