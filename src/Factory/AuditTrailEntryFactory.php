<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Factory;

use Doctrine\Persistence\Proxy;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\User\AuditActor;

final class AuditTrailEntryFactory
{
    /**
     * @param array{before: array<string, mixed>, after: array<string, mixed>} $diff
     * @param array<string, mixed>                                             $identifier Doctrine identifier values
     */
    public function create(object $entity, AuditAction $action, array $diff, AuditActor $actor, array $identifier, ?string $entityLabel = null): AuditTrailEntry
    {
        return new AuditTrailEntry(
            entityClass: $this->resolveRealClass($entity),
            entityId: $this->formatIdentifier($identifier),
            entityLabel: $entityLabel,
            action: $action,
            diff: $diff,
            userId: $actor->userId,
            userIdentifier: $actor->userIdentifier,
            ipAddress: $actor->ipAddress,
            userAgent: $actor->userAgent,
            actorLabel: $actor->label,
        );
    }

    /**
     * @param array<string, mixed> $identifier
     */
    private function formatIdentifier(array $identifier): string
    {
        if (1 === \count($identifier)) {
            return (string) reset($identifier);
        }

        return json_encode($identifier, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return class-string
     */
    private function resolveRealClass(object $entity): string
    {
        $class = $entity::class;

        if (is_subclass_of($class, Proxy::class)) {
            $parent = get_parent_class($class);
            if (false !== $parent) {
                return $parent;
            }
        }

        return $class;
    }
}
