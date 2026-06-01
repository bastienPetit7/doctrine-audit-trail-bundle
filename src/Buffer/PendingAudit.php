<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Buffer;

use Metadev\AuditLogBundle\Enum\AuditAction;

final class PendingAudit
{
    /**
     * @param array{before?: array<string, mixed>, after?: array<string, mixed>} $diff
     * @param array<string, mixed>|null                                          $identifier Doctrine identifier values
     */
    public function __construct(
        public readonly object $entity,
        public readonly AuditAction $action,
        public readonly array $diff,
        public ?array $identifier = null,
    ) {
    }
}
