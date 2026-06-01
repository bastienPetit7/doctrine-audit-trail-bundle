<?php

declare(strict_types=1);

use Metadev\AuditLogBundle\Metadata\AuditMetadataFactory;
use Metadev\AuditLogBundle\Repository\AuditLogRepository;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set(AuditLogRepository::class);

    $services->set(AuditMetadataFactory::class)
        ->args([param('audit_log.ignored_fields')]);
};
