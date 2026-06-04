<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;

trait InMemoryAuditEntityManagerTrait
{
    private function createAuditEntityManager(): EntityManagerInterface
    {
        return $this->buildEntityManager([\dirname(__DIR__, 2).'/src/Entity'], [AuditTrailEntry::class]);
    }

    /**
     * @param list<string>       $paths   attribute-mapping paths to scan
     * @param list<class-string> $classes entities whose schema must be created
     */
    private function buildEntityManager(array $paths, array $classes): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(paths: $paths, isDevMode: true);
        if (method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema(array_map(
            static fn (string $class): ClassMetadata => $entityManager->getClassMetadata($class),
            $classes,
        ));

        return $entityManager;
    }
}
