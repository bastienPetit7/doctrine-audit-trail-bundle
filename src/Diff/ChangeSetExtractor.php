<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Diff;

use Doctrine\ORM\PersistentCollection;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Enum\DeleteSnapshotMode;
use Metadev\DoctrineAuditTrailBundle\Metadata\AuditMetadata;
use Metadev\DoctrineAuditTrailBundle\Util\CanonicalJson;

final class ChangeSetExtractor
{
    public const NO_SIZE_LIMIT = 0;

    public function __construct(
        private readonly DiffFormatterRegistry $formatters,
        private readonly int $maxSizeBytes = 65536,
        private readonly DeleteSnapshotMode $deleteSnapshotMode = DeleteSnapshotMode::Minimal,
    ) {
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}|PersistentCollection> $changeSet Doctrine UoW change set
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function extractChanges(array $changeSet, AuditMetadata $metadata): array
    {
        $before = [];
        $after = [];

        foreach ($changeSet as $field => $values) {
            if (!\is_array($values)) {
                continue;
            }

            if ($metadata->isFieldIgnored($field)) {
                continue;
            }

            [$old, $new] = $values;
            $before[$field] = $old;
            $after[$field] = $new;
        }

        return ['before' => $before, 'after' => $after];
    }

    /**
     * @param array<string, mixed> $fieldValues
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function extractDeletion(array $fieldValues, AuditMetadata $metadata): array
    {
        $before = [];

        foreach ($fieldValues as $field => $value) {
            if ($metadata->isFieldIgnored($field)) {
                continue;
            }

            $before[$field] = $value;
        }

        return ['before' => $before, 'after' => []];
    }

    /**
     * @param array{before: array<string, mixed>, after: array<string, mixed>} $raw
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function format(array $raw, AuditAction $action): array
    {
        $before = [];
        foreach ($raw['before'] as $field => $value) {
            $before[$field] = $this->formatters->format($value);
        }

        $after = [];
        foreach ($raw['after'] as $field => $value) {
            $after[$field] = $this->formatters->format($value);
        }

        if (AuditAction::Delete === $action && DeleteSnapshotMode::Minimal === $this->deleteSnapshotMode) {
            return $this->minimalDeletionSnapshot($before);
        }

        return $this->enforceSizeQuota(['before' => $before, 'after' => $after]);
    }

    /**
     * @param array<string, mixed> $before
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function minimalDeletionSnapshot(array $before): array
    {
        try {
            $canonical = CanonicalJson::encode($before);
        } catch (\JsonException) {
            return $this->truncationMarker('encoding_failed');
        }

        return [
            'before' => ['_snapshot_hash' => hash('sha256', $canonical)],
            'after' => [],
        ];
    }

    /**
     * @param array{before: array<string, mixed>, after: array<string, mixed>} $diff
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function enforceSizeQuota(array $diff): array
    {
        if (self::NO_SIZE_LIMIT === $this->maxSizeBytes) {
            return $diff;
        }

        try {
            $encoded = json_encode($diff, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->truncationMarker('encoding_failed');
        }

        $size = \strlen($encoded);
        if ($size <= $this->maxSizeBytes) {
            return $diff;
        }

        return $this->truncationMarker('size_exceeded', $size);
    }

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function truncationMarker(string $reason, ?int $originalSize = null): array
    {
        $after = ['_truncated' => true, '_reason' => $reason];
        if (null !== $originalSize) {
            $after['_originalSize'] = $originalSize;
        }

        return ['before' => [], 'after' => $after];
    }
}
