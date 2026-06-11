<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Factory;

use Doctrine\Persistence\Proxy;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Integrity\AuditEntrySignature;
use Metadev\DoctrineAuditTrailBundle\Integrity\SignatureProviderInterface;
use Metadev\DoctrineAuditTrailBundle\User\AuditActor;

final class AuditTrailEntryFactory
{
    public function __construct(
        private readonly ?SignatureProviderInterface $signatureProvider = null,
    ) {
    }

    /**
     * @param array{before: array<string, mixed>, after: array<string, mixed>} $diff
     * @param array<string, mixed>                                             $identifier Doctrine identifier values
     */
    public function create(object $entity, AuditAction $action, array $diff, AuditActor $actor, array $identifier, ?string $entityLabel = null): AuditTrailEntry
    {
        $entityClass = $this->resolveRealClass($entity);
        $entityId = $this->formatIdentifier($identifier);
        $createdAt = new \DateTimeImmutable();

        $signature = null === $this->signatureProvider ? null : $this->signatureProvider->sign(
            AuditEntrySignature::payload(
                $entityClass,
                $entityId,
                $action,
                $diff,
                $actor->userId,
                $actor->userIdentifier,
                $actor->ipAddress,
                $actor->userAgent,
                $actor->label,
                $createdAt,
            ),
        );

        return new AuditTrailEntry(
            entityClass: $entityClass,
            entityId: $entityId,
            entityLabel: $entityLabel,
            action: $action,
            diff: $diff,
            userId: $actor->userId,
            userIdentifier: $actor->userIdentifier,
            ipAddress: $actor->ipAddress,
            userAgent: $actor->userAgent,
            actorLabel: $actor->label,
            createdAt: $createdAt,
            signature: $signature,
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
