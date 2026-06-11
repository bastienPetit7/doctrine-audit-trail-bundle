<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Integrity;

final class HmacSignatureProvider implements SignatureProviderInterface
{
    public function __construct(
        private readonly string $secret,
    ) {
    }

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
