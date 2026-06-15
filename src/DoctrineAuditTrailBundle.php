<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle;

use Metadev\DoctrineAuditTrailBundle\DependencyInjection\Compiler\AuditFormatterPass;
use Metadev\DoctrineAuditTrailBundle\Doctrine\EventListener\AuditTrailListener;
use Metadev\DoctrineAuditTrailBundle\Integrity\HmacSignatureProvider;
use Metadev\DoctrineAuditTrailBundle\Integrity\SignatureProviderInterface;
use Metadev\DoctrineAuditTrailBundle\Messenger\PersistAuditTrailEntriesHandler;
use Metadev\DoctrineAuditTrailBundle\Persister\AuditPersisterInterface;
use Metadev\DoctrineAuditTrailBundle\Persister\DoctrineAuditPersister;
use Metadev\DoctrineAuditTrailBundle\Persister\MessengerAuditPersister;
use Metadev\DoctrineAuditTrailBundle\Persister\SoftFailAuditPersister;
use Metadev\DoctrineAuditTrailBundle\User\AuditUserResolverInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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
                ->arrayNode('diff')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_size_bytes')
                            ->info('Hard cap on the JSON-encoded diff payload. Beyond this size the diff is replaced with a truncation marker, preventing a single mutation from bloating the audit table. Use 0 to disable the guard.')
                            ->min(0)
                            ->defaultValue(65536)
                        ->end()
                    ->end()
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
                ->arrayNode('persistence')
                    ->info('How audit entries reach the store. See the "Consistency model" section of the README.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('mode')
                            ->info('sync: write in the request (default). async: offload to Symfony Messenger.')
                            ->values(['sync', 'async'])
                            ->defaultValue('sync')
                        ->end()
                        ->booleanNode('soft_fail')
                            ->info('When true, a failing audit write is caught and logged (PSR logger) instead of breaking the application transaction.')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('message_bus')
                            ->info('Message bus service id used in async mode.')
                            ->defaultValue('messenger.bus.default')
                        ->end()
                        ->integerNode('batch_size')
                            ->info('Async mode: max entries per Messenger message. Keeps payloads under transport limits (AMQP frame_max, Redis stream entry size).')
                            ->min(1)
                            ->defaultValue(MessengerAuditPersister::DEFAULT_BATCH_SIZE)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('integrity')
                    ->info('Opt-in HMAC tamper-evidence seal written on each audit row.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('When true, every audit row is sealed with an HMAC signature.')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('secret')
                            ->info('Secret used by the default HMAC provider (use an env var). Required when enabled without a custom secret_provider.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('secret_provider')
                            ->info('Custom SignatureProviderInterface service id (e.g. a KMS/Vault-backed provider).')
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
        $builder->setParameter('doctrine_audit_trail.diff.max_size_bytes', $config['diff']['max_size_bytes'] ?? 65536);
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

        $this->configurePersistence($config['persistence'] ?? [], $container);
        $this->configureIntegrity($config['integrity'] ?? [], $container);
    }

    /**
     * @param array<string, mixed> $persistence
     */
    private function configurePersistence(array $persistence, ContainerConfigurator $container): void
    {
        $services = $container->services();
        $innerId = DoctrineAuditPersister::class;

        if ('async' === ($persistence['mode'] ?? 'sync')) {
            if (!interface_exists(MessageBusInterface::class)) {
                throw new InvalidConfigurationException('doctrine_audit_trail.persistence: mode "async" requires symfony/messenger. Run "composer require symfony/messenger".');
            }

            $services->set(MessengerAuditPersister::class)
                ->args([
                    service($persistence['message_bus'] ?? 'messenger.bus.default'),
                    $persistence['batch_size'] ?? MessengerAuditPersister::DEFAULT_BATCH_SIZE,
                ]);

            $services->set(PersistAuditTrailEntriesHandler::class)
                ->autoconfigure(false)
                ->args([service(DoctrineAuditPersister::class)])
                ->tag('messenger.message_handler');

            $innerId = MessengerAuditPersister::class;
        }

        if (true === ($persistence['soft_fail'] ?? false)) {
            $services->set(SoftFailAuditPersister::class)
                ->args([service($innerId), service('logger')->nullOnInvalid()]);

            $innerId = SoftFailAuditPersister::class;
        }

        $services->alias(AuditPersisterInterface::class, $innerId);
    }

    /**
     * @param array<string, mixed> $integrity
     */
    private function configureIntegrity(array $integrity, ContainerConfigurator $container): void
    {
        if (true !== ($integrity['enabled'] ?? false)) {
            return;
        }

        $providerId = $integrity['secret_provider'] ?? null;
        if (null !== $providerId) {
            $container->services()->alias(SignatureProviderInterface::class, $providerId);

            return;
        }

        $secret = $integrity['secret'] ?? null;
        if (null === $secret || '' === $secret) {
            throw new InvalidConfigurationException('doctrine_audit_trail.integrity: "secret" is required when integrity is enabled and no "secret_provider" is configured.');
        }

        $container->services()
            ->set(HmacSignatureProvider::class)
            ->args([$secret]);
        $container->services()->alias(SignatureProviderInterface::class, HmacSignatureProvider::class);
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
