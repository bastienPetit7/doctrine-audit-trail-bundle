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
        return isset($this->ignoredFields[$field]);
    }
}
