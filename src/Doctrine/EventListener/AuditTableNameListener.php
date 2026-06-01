<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Doctrine\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Metadev\AuditLogBundle\Entity\AuditLog;

#[AsDoctrineListener(event: Events::loadClassMetadata)]
final class AuditTableNameListener
{
    public function __construct(
        private readonly string $tableName,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $classMetadata = $args->getClassMetadata();

        if (AuditLog::class !== $classMetadata->getName()) {
            return;
        }

        $classMetadata->setPrimaryTable(['name' => $this->tableName]);
    }
}
