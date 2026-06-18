<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Diff;

use Metadev\DoctrineAuditTrailBundle\Enum\DeleteSnapshotMode;
use Metadev\DoctrineAuditTrailBundle\Util\CanonicalJson;

final class DeleteSnapshotPolicy
{
    public function __construct(
        private readonly DeleteSnapshotMode $mode = DeleteSnapshotMode::Minimal,
    ) {
    }

    /**
     * @param array{before: array<string, mixed>, after: array<string, mixed>} $diff
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function apply(array $diff): array
    {
        if (DeleteSnapshotMode::Minimal !== $this->mode) {
            return $diff;
        }

        try {
            $canonical = CanonicalJson::encode($diff['before']);
        } catch (\JsonException) {
            return TruncationMarker::encodingFailed();
        }

        return [
            'before' => ['_snapshot_hash' => hash('sha256', $canonical)],
            'after' => [],
        ];
    }
}
