<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\User;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class DefaultAuditUserResolver implements AuditUserResolverInterface
{
    public function __construct(
        private readonly AuditContextHolder $contextHolder,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly string $fallbackLabel = 'cli',
    ) {
    }

    public function resolve(): AuditActor
    {
        if (null !== $actor = $this->contextHolder->getActor()) {
            return $actor;
        }

        $request = $this->requestStack?->getCurrentRequest();
        $user = $this->tokenStorage?->getToken()?->getUser();

        if ($user instanceof UserInterface) {
            return new AuditActor(
                label: $user->getUserIdentifier(),
                userId: $this->extractUserId($user),
                userIdentifier: $user->getUserIdentifier(),
                ipAddress: $request?->getClientIp(),
                userAgent: $this->userAgentOf($request),
            );
        }

        if (null !== $request) {
            return new AuditActor(
                label: 'anonymous',
                ipAddress: $request->getClientIp(),
                userAgent: $this->userAgentOf($request),
            );
        }

        return new AuditActor(label: $this->fallbackLabel);
    }

    private function extractUserId(UserInterface $user): ?string
    {
        if (method_exists($user, 'getId')) {
            $id = $user->getId();

            return null === $id ? null : (string) $id;
        }

        return null;
    }

    private function userAgentOf(?Request $request): ?string
    {
        return $request?->headers->get('User-Agent');
    }
}
