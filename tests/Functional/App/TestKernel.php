<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Functional\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Metadev\DoctrineAuditTrailBundle\DoctrineAuditTrailBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    private readonly string $baseDir;

    public function __construct(string $environment = 'test', bool $debug = true)
    {
        $this->baseDir = sys_get_temp_dir().'/doctrine_audit_trail_test/'.bin2hex(random_bytes(8));

        parent::__construct($environment, $debug);
    }

    public function shutdown(): void
    {
        parent::shutdown();

        if (is_dir($this->baseDir)) {
            (new Filesystem())->remove($this->baseDir);
        }
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new DoctrineAuditTrailBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'handle_all_throwables' => true,
            ]);

            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'default_connection' => 'default',
                    'connections' => [
                        'default' => ['url' => 'sqlite:///:memory:'],
                        'audit' => ['url' => 'sqlite:///:memory:'],
                    ],
                ],
                'orm' => [
                    'default_entity_manager' => 'default',
                    'entity_managers' => [
                        'default' => [
                            'connection' => 'default',
                            'auto_mapping' => false,
                        ],
                        'audit' => [
                            'connection' => 'audit',
                        ],
                    ],
                ],
            ]);

            $container->loadFromExtension('doctrine_audit_trail', [
                'enabled' => true,
                'storage' => [
                    'entity_manager' => 'audit',
                    'table_name' => 'custom_audit',
                ],
                'ignored_fields' => ['password', 'plainPassword'],
                'actor' => [
                    'fallback_label' => 'test-cli',
                ],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return $this->baseDir.'/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->baseDir.'/logs';
    }
}
