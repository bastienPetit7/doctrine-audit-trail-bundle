<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Integrity;

use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;

final class AuditEntrySignature
{
    /**
     * @param array{before: array<string, mixed>, after: array<string, mixed>} $diff
     */
    public static function payload(
        string $entityClass,
        string $entityId,
        AuditAction $action,
        array $diff,
        ?string $userId,
        ?string $userIdentifier,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $actorLabel,
        \DateTimeImmutable $createdAt,
    ): string {
        self::ksortRecursive($diff);

        return json_encode([
            'entityClass' => $entityClass,
            'entityId' => $entityId,
            'action' => $action->value,
            'diff' => $diff,
            'userId' => $userId,
            'userIdentifier' => $userIdentifier,
            'ipAddress' => $ipAddress,
            'userAgent' => $userAgent,
            'actorLabel' => $actorLabel,
            'createdAt' => $createdAt->format(\DateTimeInterface::ATOM),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    public static function payloadFor(AuditTrailEntry $entry): string
    {
        return self::payload(
            $entry->getEntityClass(),
            $entry->getEntityId(),
            $entry->getAction(),
            $entry->getDiff(),
            $entry->getUserId(),
            $entry->getUserIdentifier(),
            $entry->getIpAddress(),
            $entry->getUserAgent(),
            $entry->getActorLabel(),
            $entry->getCreatedAt(),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function ksortRecursive(array &$data): void
    {
        foreach ($data as &$value) {
            if (\is_array($value)) {
                self::ksortRecursive($value);
            }
        }
        unset($value);

        ksort($data);
    }
}
