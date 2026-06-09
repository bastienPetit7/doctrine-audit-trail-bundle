<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Functional;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Metadev\DoctrineAuditTrailBundle\Diff\DiffFormatterRegistry;
use Metadev\DoctrineAuditTrailBundle\Doctrine\EventListener\AuditTrailListener;
use Metadev\DoctrineAuditTrailBundle\DoctrineAuditTrailBundle;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Persister\AuditPersisterInterface;
use Metadev\DoctrineAuditTrailBundle\Persister\DoctrineAuditPersister;
use Metadev\DoctrineAuditTrailBundle\Tests\Functional\Resources\StaticUserResolver;
use Metadev\DoctrineAuditTrailBundle\User\AuditUserResolverInterface;
use Metadev\DoctrineAuditTrailBundle\User\DefaultAuditUserResolver;
use Nyholm\BundleTest\TestKernel;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Integration tests that boot the bundle inside a real Symfony container and
 * verify the wiring effects, not just service definitions (covered in unit tests).
 *
 * @internal
 */
#[Medium]
final class BundleBootTest extends KernelTestCase
{
    private const DEFAULT_CONFIG = __DIR__.'/Resources/config/audit_default.yaml';
    private const CUSTOM_CONFIG = __DIR__.'/Resources/config/audit_custom.yaml';
    private const DISABLED_CONFIG = __DIR__.'/Resources/config/audit_disabled.yaml';
    private const CUSTOM_RESOLVER_CONFIG = __DIR__.'/Resources/config/audit_with_custom_resolver.yaml';

    private mixed $errorHandlerSnapshot = null;
    private mixed $exceptionHandlerSnapshot = null;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array{
     *     bundles?: list<class-string<\Symfony\Component\HttpKernel\Bundle\BundleInterface>>,
     *     baseConfigs?: list<string>,
     *     config?: callable,
     * } $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $bundles = $options['bundles'] ?? [
            FrameworkBundle::class,
            DoctrineBundle::class,
            DoctrineAuditTrailBundle::class,
        ];
        $baseConfigs = $options['baseConfigs'] ?? [
            __DIR__.'/Resources/config/framework.yaml',
            __DIR__.'/Resources/config/doctrine.yaml',
        ];

        /** @var TestKernel $kernel */
        $kernel = parent::createKernel($options);

        foreach ($bundles as $bundle) {
            $kernel->addTestBundle($bundle);
        }
        foreach ($baseConfigs as $config) {
            $kernel->addTestConfig($config);
        }

        $kernel->handleOptions($options);

        return $kernel;
    }

    /**
     * Symfony's HttpKernel ErrorListener and other framework components install
     * global PHP error/exception handlers that survive `kernel->shutdown()`.
     * PHPUnit's `failOnRisky` flags this as a leaked handler. We snapshot the
     * stack top before each test, then pop down to it on teardown.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->errorHandlerSnapshot = set_error_handler(null);
        restore_error_handler();

        $this->exceptionHandlerSnapshot = set_exception_handler(null);
        restore_exception_handler();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->popHandlersDownTo(
            set_error_handler(...),
            restore_error_handler(...),
            static fn (int $errno, string $errstr): bool => false,
            $this->errorHandlerSnapshot,
        );

        $this->popHandlersDownTo(
            set_exception_handler(...),
            restore_exception_handler(...),
            static function (\Throwable $e): void {},
            $this->exceptionHandlerSnapshot,
        );
    }

    private function popHandlersDownTo(callable $set, callable $restore, callable $dummy, mixed $snapshot): void
    {
        while (true) {
            $current = $set($dummy);
            $restore();

            if (null === $current || $current === $snapshot) {
                break;
            }

            $restore();
        }
    }

    private function bootWithAuditConfig(string $auditConfigPath): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel) use ($auditConfigPath): void {
            $kernel->addTestConfig($auditConfigPath);
        }]);
    }

    #[Test]
    public function it_should_boot_with_default_configuration(): void
    {
        $this->bootWithAuditConfig(self::DEFAULT_CONFIG);

        $container = self::getContainer();

        self::assertTrue($container->has(AuditTrailListener::class));
        self::assertTrue($container->has(AuditPersisterInterface::class));
        self::assertInstanceOf(DoctrineAuditPersister::class, $container->get(AuditPersisterInterface::class));
        self::assertSame('audit_trail', $container->getParameter('doctrine_audit_trail.storage.table_name'));
    }

    #[Test]
    public function it_should_apply_a_fully_customised_configuration(): void
    {
        $this->bootWithAuditConfig(self::CUSTOM_CONFIG);

        $container = self::getContainer();

        self::assertTrue($container->getParameter('doctrine_audit_trail.enabled'));
        self::assertSame(['password', 'plainPassword'], $container->getParameter('doctrine_audit_trail.ignored_fields'));
        self::assertSame('custom_audit', $container->getParameter('doctrine_audit_trail.storage.table_name'));
        self::assertSame('test-cli', $container->getParameter('doctrine_audit_trail.actor.fallback_label'));
    }

    #[Test]
    public function it_should_register_the_audit_trail_entry_mapping_on_the_configured_entity_manager(): void
    {
        $this->bootWithAuditConfig(self::CUSTOM_CONFIG);

        /** @var EntityManagerInterface $auditEm */
        $auditEm = self::getContainer()->get('doctrine.orm.audit_entity_manager');

        /** @var ClassMetadata<AuditTrailEntry> $metadata */
        $metadata = $auditEm->getClassMetadata(AuditTrailEntry::class);
        self::assertSame('custom_audit', $metadata->getTableName());
    }

    #[Test]
    public function it_should_wire_the_audit_persister_to_the_configured_audit_entity_manager(): void
    {
        $this->bootWithAuditConfig(self::DEFAULT_CONFIG);

        $persister = self::getContainer()->get(AuditPersisterInterface::class);
        $auditEm = self::getContainer()->get('doctrine.orm.audit_entity_manager');

        self::assertInstanceOf(DoctrineAuditPersister::class, $persister);

        $emProperty = (new \ReflectionObject($persister))->getProperty('auditEntityManager');

        self::assertSame($auditEm, $emProperty->getValue($persister));
    }

    #[Test]
    public function it_should_format_a_datetime_value_through_the_default_formatter_chain(): void
    {
        $this->bootWithAuditConfig(self::DEFAULT_CONFIG);

        $registry = self::getContainer()->get(DiffFormatterRegistry::class);

        self::assertInstanceOf(DiffFormatterRegistry::class, $registry);
        self::assertSame(
            '2026-01-01T00:00:00+00:00',
            $registry->format(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')),
        );
    }

    #[Test]
    public function it_should_resolve_the_default_user_resolver_when_no_custom_one_is_configured(): void
    {
        $this->bootWithAuditConfig(self::DEFAULT_CONFIG);

        $resolver = self::getContainer()->get(AuditUserResolverInterface::class);

        self::assertInstanceOf(DefaultAuditUserResolver::class, $resolver);

        // Outside an HTTP request and with no SecurityBundle present, the resolver
        // must still return an actor labelled with the configured CLI fallback.
        $actor = $resolver->resolve();
        self::assertSame('cli', $actor->label);
    }

    #[Test]
    public function it_should_swap_the_user_resolver_alias_for_a_user_defined_implementation(): void
    {
        $this->bootWithAuditConfig(self::CUSTOM_RESOLVER_CONFIG);

        $resolver = self::getContainer()->get(AuditUserResolverInterface::class);

        self::assertInstanceOf(StaticUserResolver::class, $resolver);
        self::assertSame('static-test-user', $resolver->resolve()->label);
    }

    #[Test]
    public function it_should_inject_the_disabled_flag_into_the_listener(): void
    {
        $this->bootWithAuditConfig(self::DISABLED_CONFIG);

        $container = self::getContainer();
        $listener = $container->get(AuditTrailListener::class);

        self::assertInstanceOf(AuditTrailListener::class, $listener);
        self::assertFalse($container->getParameter('doctrine_audit_trail.enabled'));

        // Behavioural check: the constructor-bound flag is what shouldHandle() reads.
        $enabledProperty = (new \ReflectionObject($listener))->getProperty('enabled');
        self::assertFalse($enabledProperty->getValue($listener));
    }
}
