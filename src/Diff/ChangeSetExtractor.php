<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Diff;

use Doctrine\ORM\PersistentCollection;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Metadata\AuditMetadata;

final class ChangeSetExtractor
{
    public const COLLECTION_MARKER = '_collection';

    public function __construct(
        private readonly DiffFormatterRegistry $formatters,
        private readonly DiffSizeGuard $sizeGuard,
        private readonly DeleteSnapshotPolicy $deleteSnapshotPolicy,
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
     * @return array{_collection: true, added: list<object>, removed: list<object>}
     */
    public function extractCollection(PersistentCollection $collection): array
    {
        return [
            self::COLLECTION_MARKER => true,
            'added' => array_values($collection->getInsertDiff()),
            'removed' => array_values($collection->getDeleteDiff()),
        ];
    }

    /**
     * @return array{_collection: true, added: list<object>, removed: list<object>}
     */
    public function extractClearedCollection(PersistentCollection $collection): array
    {
        return [
            self::COLLECTION_MARKER => true,
            'added' => [],
            'removed' => array_values($collection->getSnapshot()),
        ];
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
        $diff = [
            'before' => $this->formatSide($raw['before']),
            'after' => $this->formatSide($raw['after']),
        ];

        if (AuditAction::Delete === $action) {
            $diff = $this->deleteSnapshotPolicy->apply($diff);
        }

        return $this->sizeGuard->apply($diff);
    }

    /**
     * @param array<string, mixed> $side
     *
     * @return array<string, mixed>
     */
    private function formatSide(array $side): array
    {
        $formatted = [];
        foreach ($side as $field => $value) {
            $formatted[$field] = $this->formatField($value);
        }

        return $formatted;
    }

    private function formatField(mixed $value): mixed
    {
        if (\is_array($value) && true === ($value[self::COLLECTION_MARKER] ?? false)) {
            return [
                self::COLLECTION_MARKER => true,
                'added' => array_map(fn (mixed $item): mixed => $this->formatters->format($item), $value['added'] ?? []),
                'removed' => array_map(fn (mixed $item): mixed => $this->formatters->format($item), $value['removed'] ?? []),
            ];
        }

        return $this->formatters->format($value);
    }
}
