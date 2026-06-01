<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Doctrine\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ObjectManager;
use Metadev\AuditLogBundle\Buffer\PendingAudit;
use Metadev\AuditLogBundle\Buffer\PendingAuditBuffer;
use Metadev\AuditLogBundle\Diff\ChangeSetExtractor;
use Metadev\AuditLogBundle\Enum\AuditAction;
use Metadev\AuditLogBundle\Factory\AuditLogFactory;
use Metadev\AuditLogBundle\Metadata\AuditMetadataFactory;
use Metadev\AuditLogBundle\Persister\AuditPersisterInterface;
use Metadev\AuditLogBundle\User\AuditUserResolverInterface;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AuditLogListener
{
    public function __construct(
        private readonly AuditMetadataFactory $metadataFactory,
        private readonly ChangeSetExtractor $changeSetExtractor,
        private readonly AuditLogFactory $auditLogFactory,
        private readonly AuditPersisterInterface $persister,
        private readonly AuditUserResolverInterface $userResolver,
        private readonly PendingAuditBuffer $buffer,
        private readonly EntityManagerInterface $auditEntityManager,
        private readonly bool $enabled,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        if (!$this->shouldHandle($entityManager)) {
            return;
        }

        $unitOfWork = $entityManager->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $metadata = $this->metadataFactory->getMetadata($entity);
            if (!$metadata->auditable) {
                continue;
            }

            $diff = $this->changeSetExtractor->extractChanges($unitOfWork->getEntityChangeSet($entity), $metadata);
            // Identifier completed on postPersist (may be generated on insert).
            $this->buffer->add(new PendingAudit($entity, AuditAction::Create, $diff));
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $metadata = $this->metadataFactory->getMetadata($entity);
            if (!$metadata->auditable) {
                continue;
            }

            $diff = $this->changeSetExtractor->extractChanges($unitOfWork->getEntityChangeSet($entity), $metadata);
            $this->buffer->add(new PendingAudit(
                $entity,
                AuditAction::Update,
                $diff,
                $this->identifierOf($entityManager, $entity),
            ));
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $metadata = $this->metadataFactory->getMetadata($entity);
            if (!$metadata->auditable) {
                continue;
            }

            $diff = $this->changeSetExtractor->extractDeletion($this->fieldValuesOf($entityManager, $entity), $metadata);
            $this->buffer->add(new PendingAudit(
                $entity,
                AuditAction::Delete,
                $diff,
                $this->identifierOf($entityManager, $entity),
            ));
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        if (!$this->shouldHandle($entityManager)) {
            return;
        }

        $entity = $args->getObject();
        $pending = $this->buffer->get($entity);
        if (null === $pending) {
            return;
        }

        $pending->identifier = $this->identifierOf($entityManager, $entity);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->shouldHandle($args->getObjectManager()) || $this->buffer->isEmpty()) {
            return;
        }

        $actor = $this->userResolver->resolve();

        $logs = [];
        foreach ($this->buffer->all() as $pending) {
            if (null === $pending->identifier) {
                continue;
            }

            $logs[] = $this->auditLogFactory->create(
                $pending->entity,
                $pending->action,
                $pending->diff,
                $actor,
                $pending->identifier,
            );
        }

        // Clear before writing: the audit manager's own flush re-enters these
        // hooks but is filtered out by the provenance guard.
        $this->buffer->clear();

        $this->persister->persist($logs);
    }

    private function shouldHandle(ObjectManager $entityManager): bool
    {
        // Kill switch + provenance guard: never audit the audit store itself.
        return $this->enabled && $entityManager !== $this->auditEntityManager;
    }

    /**
     * @return array<string, mixed>
     */
    private function identifierOf(EntityManagerInterface $entityManager, object $entity): array
    {
        return $entityManager->getClassMetadata($entity::class)->getIdentifierValues($entity);
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldValuesOf(EntityManagerInterface $entityManager, object $entity): array
    {
        $classMetadata = $entityManager->getClassMetadata($entity::class);

        $values = [];
        foreach ($classMetadata->getFieldNames() as $field) {
            $values[$field] = $classMetadata->getFieldValue($entity, $field);
        }

        return $values;
    }
}
