<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Functional;

use Metadev\DoctrineAuditTrailBundle\Diff\DiffFormatterRegistry;
use Metadev\DoctrineAuditTrailBundle\Diff\Formatter\ScalarValueFormatter;
use Metadev\DoctrineAuditTrailBundle\Doctrine\EventListener\AuditTableNameListener;
use Metadev\DoctrineAuditTrailBundle\Doctrine\EventListener\AuditTrailListener;
use Metadev\DoctrineAuditTrailBundle\Factory\AuditTrailEntryFactory;
use Metadev\DoctrineAuditTrailBundle\Metadata\AuditMetadataFactory;
use Metadev\DoctrineAuditTrailBundle\Persister\AuditPersisterInterface;
use Metadev\DoctrineAuditTrailBundle\Persister\DoctrineAuditPersister;
use Metadev\DoctrineAuditTrailBundle\Tests\Functional\App\TestKernel;
use Metadev\DoctrineAuditTrailBundle\User\AuditContextHolder;
use Metadev\DoctrineAuditTrailBundle\User\AuditUserResolverInterface;
use Metadev\DoctrineAuditTrailBundle\User\DefaultAuditUserResolver;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BundleBootTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    private mixed $errorHandlerSnapshot = null;

    private mixed $exceptionHandlerSnapshot = null;

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

    #[Test]
    public function it_should_boot_the_kernel_without_errors(): void
    {
        self::bootKernel();

        self::assertTrue(self::getContainer()->has('doctrine'));
    }

    #[Test]
    public function it_should_register_the_audit_trail_listener(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            AuditTrailListener::class,
            self::getContainer()->get(AuditTrailListener::class),
        );
    }

    #[Test]
    public function it_should_register_the_table_name_listener(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            AuditTableNameListener::class,
            self::getContainer()->get(AuditTableNameListener::class),
        );
    }

    #[Test]
    public function it_should_register_the_persister_interface_alias(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            DoctrineAuditPersister::class,
            self::getContainer()->get(AuditPersisterInterface::class),
        );
    }

    #[Test]
    public function it_should_register_the_user_resolver_interface_alias(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            DefaultAuditUserResolver::class,
            self::getContainer()->get(AuditUserResolverInterface::class),
        );
    }

    #[Test]
    public function it_should_register_the_metadata_factory(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            AuditMetadataFactory::class,
            self::getContainer()->get(AuditMetadataFactory::class),
        );
    }

    #[Test]
    public function it_should_register_the_entry_factory(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            AuditTrailEntryFactory::class,
            self::getContainer()->get(AuditTrailEntryFactory::class),
        );
    }

    #[Test]
    public function it_should_register_the_context_holder(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            AuditContextHolder::class,
            self::getContainer()->get(AuditContextHolder::class),
        );
    }

    #[Test]
    public function it_should_inject_formatters_into_the_registry(): void
    {
        self::bootKernel();

        $registry = self::getContainer()->get(DiffFormatterRegistry::class);

        self::assertInstanceOf(DiffFormatterRegistry::class, $registry);
        self::assertSame(
            '2026-01-01T00:00:00+00:00',
            $registry->format(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')),
        );
    }

    #[Test]
    public function it_should_register_the_scalar_value_formatter(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            ScalarValueFormatter::class,
            self::getContainer()->get(ScalarValueFormatter::class),
        );
    }

    #[Test]
    public function it_should_apply_config_parameters(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        self::assertTrue($container->getParameter('doctrine_audit_trail.enabled'));
        self::assertSame(['password', 'plainPassword'], $container->getParameter('doctrine_audit_trail.ignored_fields'));
        self::assertSame('test-cli', $container->getParameter('doctrine_audit_trail.actor.fallback_label'));
        self::assertSame('custom_audit', $container->getParameter('doctrine_audit_trail.storage.table_name'));
    }
}
