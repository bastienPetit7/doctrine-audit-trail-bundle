<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\Proxy;

final class StubManagerRegistry extends AbstractManagerRegistry
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct(
            name: 'audit',
            connections: ['default' => 'default'],
            managers: ['default' => 'default'],
            defaultConnection: 'default',
            defaultManager: 'default',
            proxyInterfaceName: Proxy::class,
        );
    }

    /**
     * Untyped param keeps us compatible with both doctrine/persistence 2.x
     * (signature `getService($name)`) and 3.x+ (`getService(string $name): object`).
     *
     * @param string $name
     */
    protected function getService($name): object
    {
        return $this->entityManager;
    }

    /**
     * @param string $name
     */
    protected function resetService($name): void
    {
    }

    /**
     * @param string $alias
     */
    public function getAliasNamespace($alias): string
    {
        return '';
    }
}
