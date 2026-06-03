<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\User;

final class AuditActor
{
    public function __construct(
        public readonly string $label,
        public readonly ?string $userId = null,
        public readonly ?string $userIdentifier = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {
    }
}
