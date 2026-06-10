<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Repository\AuditTrailEntryRepository;

#[ORM\Entity(repositoryClass: AuditTrailEntryRepository::class)]
#[ORM\Table(name: 'audit_trail')]
#[ORM\Index(name: 'idx_audit_trail_entity', fields: ['entityClass', 'entityId'])]
#[ORM\Index(name: 'idx_audit_trail_created_at', fields: ['createdAt'])]
class AuditTrailEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * @param array{before: array<string, mixed>, after: array<string, mixed>} $diff
     */
    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 255)]
        private readonly string $entityClass,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private readonly string $entityId,
        #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        private readonly ?string $entityLabel,
        #[ORM\Column(type: Types::STRING, length: 16, enumType: AuditAction::class)]
        private readonly AuditAction $action,
        #[ORM\Column(type: Types::JSON)]
        private readonly array $diff,
        #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        private readonly ?string $userId,
        #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        private readonly ?string $userIdentifier,
        #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
        private readonly ?string $ipAddress,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private readonly ?string $userAgent,
        #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
        private readonly ?string $actorLabel,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getEntityLabel(): ?string
    {
        return $this->entityLabel;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function getDiff(): array
    {
        return $this->diff;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getUserIdentifier(): ?string
    {
        return $this->userIdentifier;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getActorLabel(): ?string
    {
        return $this->actorLabel;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
