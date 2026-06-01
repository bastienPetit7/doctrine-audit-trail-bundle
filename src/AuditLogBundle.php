<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle;

use Metadev\AuditLogBundle\DependencyInjection\Compiler\AuditFormatterPass;
use Metadev\AuditLogBundle\Doctrine\EventListener\AuditLogListener;
use Metadev\AuditLogBundle\Persister\DoctrineAuditPersister;
use Metadev\AuditLogBundle\User\AuditUserResolverInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class AuditLogBundle extends AbstractBundle
{
    private const DEFAULT_ENTITY_MANAGER = 'audit';
    private const DEFAULT_TABLE_NAME = 'audit_log';

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
                            ->info('Table name for the audit_log entity.')
                            ->defaultValue(self::DEFAULT_TABLE_NAME)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('ignored_fields')
                    ->info('Fields excluded from the diff for every audited entity.')
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

        $builder->setParameter('audit_log.enabled', $config['enabled'] ?? true);
        $builder->setParameter('audit_log.ignored_fields', $config['ignored_fields'] ?? []);
        $builder->setParameter('audit_log.actor.fallback_label', $config['actor']['fallback_label'] ?? 'cli');

        $tableName = $config['storage']['table_name'] ?? self::DEFAULT_TABLE_NAME;
        $builder->setParameter('audit_log.storage.table_name', $tableName);

        // Bind the persister and listener to the configured entity manager service.
        $entityManagerName = $config['storage']['entity_manager'] ?? self::DEFAULT_ENTITY_MANAGER;
        $entityManagerRef = new Reference(\sprintf('doctrine.orm.%s_entity_manager', $entityManagerName));
        $builder->getDefinition(DoctrineAuditPersister::class)->setArgument(0, $entityManagerRef);
        $builder->getDefinition(AuditLogListener::class)->setArgument('$auditEntityManager', $entityManagerRef);

        // Point the interface at a custom resolver service when one is configured.
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
                            'AuditLogBundle' => [
                                'type' => 'attribute',
                                'is_bundle' => false,
                                'dir' => __DIR__.'/Entity',
                                'prefix' => 'Metadev\\AuditLogBundle\\Entity',
                                'alias' => 'AuditLog',
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

        foreach ($builder->getExtensionConfig('audit_log') as $config) {
            if (isset($config['storage']['entity_manager'])) {
                $name = $config['storage']['entity_manager'];
            }
        }

        return $name;
    }
}
