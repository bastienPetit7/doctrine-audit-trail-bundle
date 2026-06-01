<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Metadev\AuditLogBundle\Entity\AuditLog;

/**
 * Builds a throwaway in-memory SQLite entity manager mapping {@see AuditLog},
 * with its schema created. Shared by the persister and listener integration tests.
 */
trait InMemoryAuditEntityManagerTrait
{
    private function createAuditEntityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [\dirname(__DIR__, 2).'/src/Entity'],
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema([$entityManager->getClassMetadata(AuditLog::class)]);

        return $entityManager;
    }
}
