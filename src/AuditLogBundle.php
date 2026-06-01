<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle;

use Metadev\AuditLogBundle\DependencyInjection\Compiler\AuditFormatterPass;
use Metadev\AuditLogBundle\User\AuditUserResolverInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class AuditLogBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        // The full configuration tree is fleshed out in a later step; for now we
        // only declare the global kill switch so the bundle boots with a schema.
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
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
                    'audit' => [
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
}
