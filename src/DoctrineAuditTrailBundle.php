<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle;

use Metadev\DoctrineAuditTrailBundle\DependencyInjection\Compiler\AuditFormatterPass;
use Metadev\DoctrineAuditTrailBundle\Doctrine\EventListener\AuditTrailListener;
use Metadev\DoctrineAuditTrailBundle\Persister\DoctrineAuditPersister;
use Metadev\DoctrineAuditTrailBundle\User\AuditUserResolverInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class DoctrineAuditTrailBundle extends AbstractBundle
{
    private const DEFAULT_ENTITY_MANAGER = 'audit';
    private const DEFAULT_TABLE_NAME = 'audit_trail';

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AuditFormatterPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('Global kill switch: when false, no audit entry is written.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('entity_manager')
                            ->info('Name of the dedicated entity manager holding audit logs.')
                            ->defaultValue(self::DEFAULT_ENTITY_MANAGER)
                        ->end()
                        ->scalarNode('table_name')
                            ->info('Table name for the audit trail entity.')
                            ->defaultValue(self::DEFAULT_TABLE_NAME)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('ignored_fields')
                    ->info('Extra fields excluded from the diff, merged on top of the built-in security blacklist.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('force_audit_fields')
                    ->info('Escape hatch: fields recorded even if present in the built-in security blacklist.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('actor')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('fallback_label')
                            ->info('Label used outside an HTTP request (CLI, messenger).')
                            ->defaultValue('cli')
                        ->end()
                        ->scalarNode('user_resolver')
                            ->info('Custom AuditUserResolverInterface service id (optional).')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__.'/../config/services.php');

        $builder->setParameter('doctrine_audit_trail.enabled', $config['enabled'] ?? true);
        $builder->setParameter('doctrine_audit_trail.ignored_fields', $config['ignored_fields'] ?? []);
        $builder->setParameter('doctrine_audit_trail.force_audit_fields', $config['force_audit_fields'] ?? []);
        $builder->setParameter('doctrine_audit_trail.actor.fallback_label', $config['actor']['fallback_label'] ?? 'cli');

        $tableName = $config['storage']['table_name'] ?? self::DEFAULT_TABLE_NAME;
        $builder->setParameter('doctrine_audit_trail.storage.table_name', $tableName);

        $entityManagerName = $config['storage']['entity_manager'] ?? self::DEFAULT_ENTITY_MANAGER;
        $entityManagerRef = new Reference(\sprintf('doctrine.orm.%s_entity_manager', $entityManagerName));
        $builder->getDefinition(DoctrineAuditPersister::class)->setArgument(0, $entityManagerRef);
        $builder->getDefinition(AuditTrailListener::class)->setArgument('$auditEntityManager', $entityManagerRef);

        if (null !== ($resolverId = $config['actor']['user_resolver'] ?? null)) {
            $container->services()->alias(AuditUserResolverInterface::class, $resolverId);
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('doctrine')) {
            return;
        }

        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'entity_managers' => [
                    $this->resolveEntityManagerName($builder) => [
                        'mappings' => [
                            'DoctrineAuditTrailBundle' => [
                                'type' => 'attribute',
                                'is_bundle' => false,
                                'dir' => __DIR__.'/Entity',
                                'prefix' => 'Metadev\\DoctrineAuditTrailBundle\\Entity',
                                'alias' => 'AuditTrail',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function resolveEntityManagerName(ContainerBuilder $builder): string
    {
        $name = self::DEFAULT_ENTITY_MANAGER;

        foreach ($builder->getExtensionConfig('doctrine_audit_trail') as $config) {
            if (isset($config['storage']['entity_manager'])) {
                $name = $config['storage']['entity_manager'];
            }
        }

        return $name;
    }
}
