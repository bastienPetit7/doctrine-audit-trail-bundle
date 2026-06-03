<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Auditable
{
    public function __construct(
        public readonly ?string $label = null,
    ) {
    }
}
