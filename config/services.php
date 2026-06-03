<?php

declare(strict_types=1);

use Metadev\DoctrineAuditTrailBundle\Buffer\PendingAuditBuffer;
use Metadev\DoctrineAuditTrailBundle\Diff\ChangeSetExtractor;
use Metadev\DoctrineAuditTrailBundle\Diff\DiffFormatterRegistry;
use Metadev\DoctrineAuditTrailBundle\Diff\Formatter\ScalarValueFormatter;
use Metadev\DoctrineAuditTrailBundle\Doctrine\EventListener\AuditTableNameListener;
use Metadev\DoctrineAuditTrailBundle\Doctrine\EventListener\AuditTrailListener;
use Metadev\DoctrineAuditTrailBundle\Factory\AuditTrailEntryFactory;
use Metadev\DoctrineAuditTrailBundle\Metadata\AuditMetadataFactory;
use Metadev\DoctrineAuditTrailBundle\Persister\AuditPersisterInterface;
use Metadev\DoctrineAuditTrailBundle\Persister\DoctrineAuditPersister;
use Metadev\DoctrineAuditTrailBundle\Repository\AuditTrailEntryRepository;
use Metadev\DoctrineAuditTrailBundle\User\AuditContextHolder;
use Metadev\DoctrineAuditTrailBundle\User\AuditUserResolverInterface;
use Metadev\DoctrineAuditTrailBundle\User\DefaultAuditUserResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set(AuditTrailEntryRepository::class);

    $services->set(AuditMetadataFactory::class)
        ->args([param('doctrine_audit_trail.ignored_fields')]);

    $services->set(AuditContextHolder::class);

    $services->set(DefaultAuditUserResolver::class)
        ->args([
            service(AuditContextHolder::class),
            service('security.token_storage')->nullOnInvalid(),
            service('request_stack')->nullOnInvalid(),
            param('doctrine_audit_trail.actor.fallback_label'),
        ]);

    // Default binding; overridden by config 'actor.user_resolver' when set.
    $services->alias(AuditUserResolverInterface::class, DefaultAuditUserResolver::class);

    $services->set(DiffFormatterRegistry::class);
    $services->set(ChangeSetExtractor::class);

    $services->set(ScalarValueFormatter::class)
        ->autoconfigure(false)
        ->tag('doctrine_audit_trail.value_formatter', ['priority' => -1000]);

    $services->set(AuditTrailEntryFactory::class);

    $services->set(DoctrineAuditPersister::class)
        ->args([service('doctrine.orm.audit_entity_manager')]);

    $services->alias(AuditPersisterInterface::class, DoctrineAuditPersister::class);

    $services->set(PendingAuditBuffer::class);

    $services->set(AuditTrailListener::class)
        ->arg('$auditEntityManager', service('doctrine.orm.audit_entity_manager'))
        ->arg('$enabled', param('doctrine_audit_trail.enabled'));

    $services->set(AuditTableNameListener::class)
        ->args([param('doctrine_audit_trail.storage.table_name')]);
};
