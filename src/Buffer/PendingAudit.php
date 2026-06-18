<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Buffer;

use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;

final class PendingAudit
{
    /**
     * @param array{before: array<string, mixed>, after: array<string, mixed>} $diff
     * @param array<string, mixed>|null                                        $identifier Doctrine identifier values
     */
    public function __construct(
        public readonly object $entity,
        public readonly AuditAction $action,
        public array $diff,
        public readonly ?string $entityLabel = null,
        public ?array $identifier = null,
    ) {
    }

    /**
     * @param array{_collection: true, added: list<object>, removed: list<object>} $delta
     */
    public function mergeCollectionDelta(string $field, array $delta): void
    {
        $this->diff['after'][$field] = $delta;
    }
}
