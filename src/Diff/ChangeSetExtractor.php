<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Diff;

use Doctrine\ORM\PersistentCollection;
use Metadev\AuditLogBundle\Metadata\AuditMetadata;

final class ChangeSetExtractor
{
    public function __construct(
        private readonly DiffFormatterRegistry $formatters,
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
            if (!is_array($values)) {
                continue;
            }

            if ($metadata->isFieldIgnored($field)) {
                continue;
            }

            [$old, $new] = $values;
            $before[$field] = $this->formatters->format($old);
            $after[$field] = $this->formatters->format($new);
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

            $before[$field] = $this->formatters->format($value);
        }

        return ['before' => $before, 'after' => []];
    }
}
