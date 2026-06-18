<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Doctrine\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ObjectManager;
use Metadev\DoctrineAuditTrailBundle\Buffer\PendingAudit;
use Metadev\DoctrineAuditTrailBundle\Buffer\PendingAuditBuffer;
use Metadev\DoctrineAuditTrailBundle\Diff\ChangeSetExtractor;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Factory\AuditTrailEntryFactory;
use Metadev\DoctrineAuditTrailBundle\Metadata\AuditMetadataFactory;
use Metadev\DoctrineAuditTrailBundle\Persister\AuditPersisterInterface;
use Metadev\DoctrineAuditTrailBundle\User\AuditUserResolverInterface;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AuditTrailListener
{
    public function __construct(
        private readonly AuditMetadataFactory $metadataFactory,
        private readonly ChangeSetExtractor $changeSetExtractor,
        private readonly AuditTrailEntryFactory $auditTrailEntryFactory,
        private readonly AuditPersisterInterface $persister,
        private readonly AuditUserResolverInterface $userResolver,
        private readonly PendingAuditBuffer $buffer,
        private readonly EntityManagerInterface $auditEntityManager,
        private readonly bool $enabled,
        private readonly bool $trackCollections = false,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        if (!$this->shouldHandle($entityManager)) {
            return;
        }

        $unitOfWork = $entityManager->getUnitOfWork();

        $this->collectInsertions($unitOfWork);
        $this->collectUpdates($entityManager, $unitOfWork);
        $this->collectDeletions($entityManager, $unitOfWork);

        if ($this->trackCollections) {
            $this->collectCollectionChanges($entityManager, $unitOfWork);
        }
    }

    private function collectInsertions(UnitOfWork $unitOfWork): void
    {
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $metadata = $this->metadataFactory->getMetadata($entity);
            if (!$metadata->auditable) {
                continue;
            }

            $diff = $this->changeSetExtractor->extractChanges($unitOfWork->getEntityChangeSet($entity), $metadata);
            $this->buffer->add(new PendingAudit($entity, AuditAction::Create, $diff, entityLabel: $metadata->label));
        }
    }

    private function collectUpdates(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork): void
    {
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $metadata = $this->metadataFactory->getMetadata($entity);
            if (!$metadata->auditable) {
                continue;
            }

            $diff = $this->changeSetExtractor->extractChanges($unitOfWork->getEntityChangeSet($entity), $metadata);

            if ([] === $diff['before'] && [] === $diff['after']) {
                continue;
            }

            $this->buffer->add(new PendingAudit(
                $entity,
                AuditAction::Update,
                $diff,
                entityLabel: $metadata->label,
                identifier: $this->identifierOf($entityManager, $entity),
            ));
        }
    }

    private function collectDeletions(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork): void
    {
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
                entityLabel: $metadata->label,
                identifier: $this->identifierOf($entityManager, $entity),
            ));
        }
    }

    private function collectCollectionChanges(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork): void
    {
        foreach ($unitOfWork->getScheduledCollectionUpdates() as $collection) {
            $this->bufferCollectionDelta(
                $entityManager,
                $collection,
                $this->changeSetExtractor->extractCollection($collection),
            );
        }

        foreach ($unitOfWork->getScheduledCollectionDeletions() as $collection) {
            $this->bufferCollectionDelta(
                $entityManager,
                $collection,
                $this->changeSetExtractor->extractClearedCollection($collection),
            );
        }
    }

    /**
     * @param array{_collection: true, added: list<object>, removed: list<object>} $delta
     */
    private function bufferCollectionDelta(
        EntityManagerInterface $entityManager,
        PersistentCollection $collection,
        array $delta,
    ): void {
        if ([] === $delta['added'] && [] === $delta['removed']) {
            return;
        }

        $owner = $collection->getOwner();
        if (null === $owner) {
            return;
        }

        $metadata = $this->metadataFactory->getMetadata($owner);
        if (!$metadata->auditable) {
            return;
        }

        $field = $collection->getMapping()['fieldName'] ?? null;
        if (!\is_string($field) || $metadata->isFieldIgnored($field)) {
            return;
        }

        $pending = $this->buffer->get($owner);
        if (null !== $pending) {
            if (AuditAction::Delete === $pending->action) {
                return;
            }

            $pending->mergeCollectionDelta($field, $delta);

            return;
        }

        $this->buffer->add(new PendingAudit(
            $owner,
            AuditAction::Update,
            ['before' => [], 'after' => [$field => $delta]],
            entityLabel: $metadata->label,
            identifier: $this->identifierOf($entityManager, $owner),
        ));
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

            $diff = $this->changeSetExtractor->format($pending->diff, $pending->action);

            $logs[] = $this->auditTrailEntryFactory->create(
                $pending->entity,
                $pending->action,
                $diff,
                $actor,
                $pending->identifier,
                $pending->entityLabel,
            );
        }

        // Clear before writing: the audit manager's own flush re-enters these
        // hooks but is filtered out by the provenance guard.
        $this->buffer->clear();

        $this->persister->persist($logs);
    }

    private function shouldHandle(ObjectManager $entityManager): bool
    {
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

        foreach ($classMetadata->getAssociationNames() as $field) {
            if (!$classMetadata->isSingleValuedAssociation($field)) {
                continue;
            }
            $values[$field] = $classMetadata->getFieldValue($entity, $field);
        }

        return $values;
    }
}
