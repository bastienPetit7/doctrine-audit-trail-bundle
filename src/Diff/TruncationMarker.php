<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Diff;

final class TruncationMarker
{
    public const REASON_SIZE_EXCEEDED = 'size_exceeded';
    public const REASON_ENCODING_FAILED = 'encoding_failed';

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public static function sizeExceeded(int $originalSize): array
    {
        return self::build(self::REASON_SIZE_EXCEEDED, $originalSize);
    }

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public static function encodingFailed(): array
    {
        return self::build(self::REASON_ENCODING_FAILED);
    }

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private static function build(string $reason, ?int $originalSize = null): array
    {
        $after = ['_truncated' => true, '_reason' => $reason];
        if (null !== $originalSize) {
            $after['_originalSize'] = $originalSize;
        }

        return ['before' => [], 'after' => $after];
    }
}
