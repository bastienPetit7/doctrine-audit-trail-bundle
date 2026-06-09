<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\DependencyInjection\Compiler;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use Metadev\DoctrineAuditTrailBundle\DependencyInjection\Compiler\AuditFormatterPass;
use Metadev\DoctrineAuditTrailBundle\Diff\DiffFormatterRegistry;
use Metadev\DoctrineAuditTrailBundle\Diff\Formatter\ScalarValueFormatter;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
#[Small]
final class AuditFormatterPassTest extends AbstractCompilerPassTestCase
{
    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AuditFormatterPass());
    }

    #[Test]
    public function it_should_do_nothing_when_the_registry_is_not_defined(): void
    {
        $this->setDefinition('app.some_formatter', $this->taggedFormatter(0));

        $this->compile();

        $this->assertContainerBuilderNotHasService(DiffFormatterRegistry::class);
    }

    #[Test]
    public function it_should_inject_an_empty_iterator_when_no_formatter_is_tagged(): void
    {
        $this->setDefinition(DiffFormatterRegistry::class, new Definition(DiffFormatterRegistry::class));

        $this->compile();

        $argument = $this->container->getDefinition(DiffFormatterRegistry::class)->getArgument(0);
        self::assertInstanceOf(IteratorArgument::class, $argument);
        self::assertSame([], $argument->getValues());
    }

    #[Test]
    public function it_should_inject_a_single_tagged_formatter(): void
    {
        $this->setDefinition(DiffFormatterRegistry::class, new Definition(DiffFormatterRegistry::class));
        $this->setDefinition(ScalarValueFormatter::class, $this->taggedFormatter(-1000));

        $this->compile();

        $argument = $this->container->getDefinition(DiffFormatterRegistry::class)->getArgument(0);
        self::assertInstanceOf(IteratorArgument::class, $argument);

        $references = $argument->getValues();
        self::assertCount(1, $references);
        self::assertSame(ScalarValueFormatter::class, (string) $references[0]);
    }

    #[Test]
    public function it_should_sort_tagged_formatters_by_priority_descending(): void
    {
        $this->setDefinition(DiffFormatterRegistry::class, new Definition(DiffFormatterRegistry::class));
        $this->setDefinition('low', $this->taggedFormatter(-1000));
        $this->setDefinition('high', $this->taggedFormatter(100));
        $this->setDefinition('mid', $this->taggedFormatter(0));

        $this->compile();

        $argument = $this->container->getDefinition(DiffFormatterRegistry::class)->getArgument(0);
        self::assertInstanceOf(IteratorArgument::class, $argument);

        $ids = array_map(static fn (Reference $r): string => (string) $r, $argument->getValues());
        self::assertSame(['high', 'mid', 'low'], $ids);
    }

    #[Test]
    public function it_should_ignore_services_without_the_value_formatter_tag(): void
    {
        $this->setDefinition(DiffFormatterRegistry::class, new Definition(DiffFormatterRegistry::class));
        $this->setDefinition('untagged', new Definition(ScalarValueFormatter::class));

        $this->compile();

        $argument = $this->container->getDefinition(DiffFormatterRegistry::class)->getArgument(0);
        self::assertInstanceOf(IteratorArgument::class, $argument);
        self::assertSame([], $argument->getValues());
    }

    private function taggedFormatter(int $priority): Definition
    {
        $definition = new Definition(ScalarValueFormatter::class);
        $definition->addTag('doctrine_audit_trail.value_formatter', ['priority' => $priority]);

        return $definition;
    }
}
