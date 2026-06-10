<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\DependencyInjection;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Metadev\DoctrineAuditTrailBundle\Diff\DiffFormatterRegistry;
use Metadev\DoctrineAuditTrailBundle\Diff\Formatter\ScalarValueFormatter;
use Metadev\DoctrineAuditTrailBundle\Doctrine\EventListener\AuditTableNameListener;
use Metadev\DoctrineAuditTrailBundle\Doctrine\EventListener\AuditTrailListener;
use Metadev\DoctrineAuditTrailBundle\DoctrineAuditTrailBundle;
use Metadev\DoctrineAuditTrailBundle\Factory\AuditTrailEntryFactory;
use Metadev\DoctrineAuditTrailBundle\Metadata\AuditMetadataFactory;
use Metadev\DoctrineAuditTrailBundle\Persister\AuditPersisterInterface;
use Metadev\DoctrineAuditTrailBundle\Persister\DoctrineAuditPersister;
use Metadev\DoctrineAuditTrailBundle\User\AuditContextHolder;
use Metadev\DoctrineAuditTrailBundle\User\AuditUserResolverInterface;
use Metadev\DoctrineAuditTrailBundle\User\DefaultAuditUserResolver;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
#[Small]
final class DoctrineAuditTrailExtensionTest extends AbstractExtensionTestCase
{
    protected function getContainerExtensions(): array
    {
        $extension = (new DoctrineAuditTrailBundle())->getContainerExtension();
        \assert(null !== $extension);

        return [$extension];
    }

    /**
     * Symfony 7's BundleExtension::load() reads `kernel.environment` directly when
     * building the ContainerConfigurator. Symfony 8 wraps the lookup in a
     * hasParameter() guard on `.container.known_envs`, masking this requirement
     * locally. Setting the params here keeps the test green across the matrix.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container->setParameter('kernel.environment', 'test');
        $this->container->setParameter('kernel.debug', false);
        $this->container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $this->container->setParameter('kernel.project_dir', sys_get_temp_dir());
    }

    #[Test]
    public function it_should_register_core_services_with_default_config(): void
    {
        $this->load();

        $this->assertContainerBuilderHasService(AuditTrailListener::class);
        $this->assertContainerBuilderHasService(AuditTableNameListener::class);
        $this->assertContainerBuilderHasService(DoctrineAuditPersister::class);
        $this->assertContainerBuilderHasService(DefaultAuditUserResolver::class);
        $this->assertContainerBuilderHasService(AuditMetadataFactory::class);
        $this->assertContainerBuilderHasService(AuditContextHolder::class);
        $this->assertContainerBuilderHasService(DiffFormatterRegistry::class);
        $this->assertContainerBuilderHasService(ScalarValueFormatter::class);
        $this->assertContainerBuilderHasService(AuditTrailEntryFactory::class);
    }

    #[Test]
    public function it_should_alias_persister_and_user_resolver_interfaces(): void
    {
        $this->load();

        $this->assertContainerBuilderHasAlias(AuditPersisterInterface::class, DoctrineAuditPersister::class);
        $this->assertContainerBuilderHasAlias(AuditUserResolverInterface::class, DefaultAuditUserResolver::class);
    }

    #[Test]
    public function it_should_set_default_parameters_when_config_is_empty(): void
    {
        $this->load();

        $this->assertContainerBuilderHasParameter('doctrine_audit_trail.enabled', true);
        $this->assertContainerBuilderHasParameter('doctrine_audit_trail.ignored_fields', []);
        $this->assertContainerBuilderHasParameter('doctrine_audit_trail.force_audit_fields', []);
        $this->assertContainerBuilderHasParameter('doctrine_audit_trail.actor.fallback_label', 'cli');
        $this->assertContainerBuilderHasParameter('doctrine_audit_trail.storage.table_name', 'audit_trail');
    }

    #[Test]
    public function it_should_apply_configured_parameters(): void
    {
        $this->load([
            'enabled' => false,
            'ignored_fields' => ['ssn', 'iban'],
            'force_audit_fields' => ['refreshToken'],
            'storage' => ['table_name' => 'custom_audit'],
            'actor' => ['fallback_label' => 'worker'],
        ]);

        $this->assertContainerBuilderHasParameter('doctrine_audit_trail.enabled', false);
        $this->assertContainerBuilderHasParameter('doctrine_audit_trail.ignored_fields', ['ssn', 'iban']);
        $this->assertContainerBuilderHasParameter('doctrine_audit_trail.force_audit_fields', ['refreshToken']);
        $this->assertContainerBuilderHasParameter('doctrine_audit_trail.actor.fallback_label', 'worker');
        $this->assertContainerBuilderHasParameter('doctrine_audit_trail.storage.table_name', 'custom_audit');
    }

    #[Test]
    public function it_should_wire_default_audit_entity_manager_into_persister_and_listener(): void
    {
        $this->load();

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            DoctrineAuditPersister::class,
            0,
            new Reference('doctrine.orm.audit_entity_manager'),
        );
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            AuditTrailListener::class,
            '$auditEntityManager',
            new Reference('doctrine.orm.audit_entity_manager'),
        );
    }

    #[Test]
    public function it_should_rewire_persister_and_listener_for_a_custom_entity_manager(): void
    {
        $this->load([
            'storage' => ['entity_manager' => 'custom_em'],
        ]);

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            DoctrineAuditPersister::class,
            0,
            new Reference('doctrine.orm.custom_em_entity_manager'),
        );
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            AuditTrailListener::class,
            '$auditEntityManager',
            new Reference('doctrine.orm.custom_em_entity_manager'),
        );
    }

    #[Test]
    public function it_should_override_user_resolver_alias_when_a_custom_one_is_configured(): void
    {
        // Register the target service first so the alias does not dangle.
        $this->setDefinition('app.custom_user_resolver', new Definition(\stdClass::class));

        $this->load([
            'actor' => ['user_resolver' => 'app.custom_user_resolver'],
        ]);

        $this->assertContainerBuilderHasAlias(AuditUserResolverInterface::class, 'app.custom_user_resolver');
        $this->assertContainerBuilderHasService('app.custom_user_resolver', \stdClass::class);
    }

    #[Test]
    public function it_should_tag_the_scalar_value_formatter_with_the_lowest_priority(): void
    {
        $this->load();

        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            ScalarValueFormatter::class,
            'doctrine_audit_trail.value_formatter',
            ['priority' => -1000],
        );
    }

    #[Test]
    public function it_should_inject_enabled_flag_into_listener_when_disabled(): void
    {
        $this->load(['enabled' => false]);

        $this->assertContainerBuilderHasParameter('doctrine_audit_trail.enabled', false);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            AuditTrailListener::class,
            '$enabled',
            '%doctrine_audit_trail.enabled%',
        );
    }
}
