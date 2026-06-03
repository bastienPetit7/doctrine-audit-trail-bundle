<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;

final class InMemoryUser implements UserInterface
{
    /**
     * @param non-empty-string $username
     */
    public function __construct(
        private readonly int $id,
        private readonly string $username,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
