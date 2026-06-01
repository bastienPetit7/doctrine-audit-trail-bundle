<?php

declare(strict_types=1);

use Metadev\AuditLogBundle\Diff\ChangeSetExtractor;
use Metadev\AuditLogBundle\Diff\DiffFormatterRegistry;
use Metadev\AuditLogBundle\Diff\Formatter\ScalarValueFormatter;
use Metadev\AuditLogBundle\Factory\AuditLogFactory;
use Metadev\AuditLogBundle\Metadata\AuditMetadataFactory;
use Metadev\AuditLogBundle\Persister\AuditPersisterInterface;
use Metadev\AuditLogBundle\Persister\DoctrineAuditPersister;
use Metadev\AuditLogBundle\Repository\AuditLogRepository;
use Metadev\AuditLogBundle\User\AuditContextHolder;
use Metadev\AuditLogBundle\User\AuditUserResolverInterface;
use Metadev\AuditLogBundle\User\DefaultAuditUserResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set(AuditLogRepository::class);

    $services->set(AuditMetadataFactory::class)
        ->args([param('audit_log.ignored_fields')]);

    $services->set(AuditContextHolder::class);

    $services->set(DefaultAuditUserResolver::class)
        ->args([
            service(AuditContextHolder::class),
            service('security.token_storage')->nullOnInvalid(),
            service('request_stack')->nullOnInvalid(),
            param('audit_log.actor.fallback_label'),
        ]);

    // Default binding; overridden by config 'actor.user_resolver' when set.
    $services->alias(AuditUserResolverInterface::class, DefaultAuditUserResolver::class);

    $services->set(DiffFormatterRegistry::class);
    $services->set(ChangeSetExtractor::class);

    $services->set(ScalarValueFormatter::class)
        ->autoconfigure(false)
        ->tag('audit_log.value_formatter', ['priority' => -1000]);

    // Audit entry assembly + persistence on the dedicated "audit" entity manager.
    $services->set(AuditLogFactory::class);

    $services->set(DoctrineAuditPersister::class)
        ->args([service('doctrine.orm.audit_entity_manager')]);

    $services->alias(AuditPersisterInterface::class, DoctrineAuditPersister::class);
};
