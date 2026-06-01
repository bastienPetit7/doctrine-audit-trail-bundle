<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\DependencyInjection\Compiler;

use Metadev\AuditLogBundle\Diff\DiffFormatterRegistry;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AuditFormatterPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DiffFormatterRegistry::class)) {
            return;
        }

        $formatters = $this->findAndSortTaggedServices('audit_log.value_formatter', $container);

        $container->getDefinition(DiffFormatterRegistry::class)
            ->setArgument(0, new IteratorArgument($formatters));
    }
}
