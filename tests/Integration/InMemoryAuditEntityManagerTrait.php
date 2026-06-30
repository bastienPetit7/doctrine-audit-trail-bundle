<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
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
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        if ('underscore' === getenv('AUDIT_TEST_NAMING_STRATEGY')) {
            $config->setNamingStrategy(new UnderscoreNamingStrategy());
        }

        $connection = DriverManager::getConnection(self::resolveConnectionParams(), $config);
        $entityManager = new EntityManager($connection, $config);

        $metadata = array_map(
            static fn (string $class): ClassMetadata => $entityManager->getClassMetadata($class),
            $classes,
        );

        $schemaTool = new SchemaTool($entityManager);

        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        return $entityManager;
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveConnectionParams(): array
    {
        $url = getenv('AUDIT_TEST_DATABASE_URL');

        if (false === $url || '' === $url) {
            return ['driver' => 'pdo_sqlite', 'memory' => true];
        }

        return (new DsnParser([
            'sqlite' => 'pdo_sqlite',
            'mysql' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
        ]))->parse($url);
    }
}
