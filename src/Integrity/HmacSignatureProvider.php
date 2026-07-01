<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Integrity;

final class HmacSignatureProvider implements SignatureProviderInterface
{
    private const MIN_SECRET_LENGTH = 32;

    public function __construct(
        private readonly string $secret,
    ) {
        if (\strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('doctrine_audit_trail.integrity: HMAC secret must be at least %d characters. Generate one with: openssl rand -hex 32', self::MIN_SECRET_LENGTH));
        }
    }

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
