<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Diff;

final class DiffSizeGuard
{
    public const NO_SIZE_LIMIT = 0;

    public function __construct(
        private readonly int $maxSizeBytes = 65536,
    ) {
    }

    /**
     * @param array{before: array<string, mixed>, after: array<string, mixed>} $diff
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function apply(array $diff): array
    {
        if (self::NO_SIZE_LIMIT === $this->maxSizeBytes) {
            return $diff;
        }

        try {
            $encoded = json_encode($diff, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return TruncationMarker::encodingFailed();
        }

        $size = \strlen($encoded);
        if ($size <= $this->maxSizeBytes) {
            return $diff;
        }

        return TruncationMarker::sizeExceeded($size);
    }
}
