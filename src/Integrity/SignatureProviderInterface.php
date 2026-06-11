<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Integrity;

interface SignatureProviderInterface
{
    public function sign(string $payload): string;
}
