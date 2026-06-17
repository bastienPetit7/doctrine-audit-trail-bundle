<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Metadata;

final class AuditMetadata
{
    /**
     * @param array<string, true> $ignoredFields
     */
    public function __construct(
        public readonly bool $auditable,
        public readonly array $ignoredFields = [],
        public readonly ?string $label = null,
    ) {
    }

    public function isFieldIgnored(string $field): bool
    {
        if (isset($this->ignoredFields[$field])) {
            return true;
        }

        // for embedded sub-fields as "property.subProperty".
        if (!str_contains($field, '.')) {
            return false;
        }

        foreach (explode('.', $field) as $segment) {
            if (isset($this->ignoredFields[$segment])) {
                return true;
            }
        }

        return false;
    }
}
