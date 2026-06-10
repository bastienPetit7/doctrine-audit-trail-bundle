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

    /**
     * Immutable copy with a different IP address — e.g. CNIL-style anonymisation
     * from a decorating AuditUserResolverInterface.
     */
    public function withIpAddress(?string $ipAddress): self
    {
        return new self(
            label: $this->label,
            userId: $this->userId,
            userIdentifier: $this->userIdentifier,
            ipAddress: $ipAddress,
            userAgent: $this->userAgent,
        );
    }

    /**
     * Immutable copy with a different user identifier — e.g. a salted hash to
     * pseudonymise the actor.
     */
    public function withUserIdentifier(?string $userIdentifier): self
    {
        return new self(
            label: $this->label,
            userId: $this->userId,
            userIdentifier: $userIdentifier,
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
        );
    }

    /**
     * Immutable copy with a different user agent — e.g. truncation or removal to
     * limit fingerprinting.
     */
    public function withUserAgent(?string $userAgent): self
    {
        return new self(
            label: $this->label,
            userId: $this->userId,
            userIdentifier: $this->userIdentifier,
            ipAddress: $this->ipAddress,
            userAgent: $userAgent,
        );
    }
}
