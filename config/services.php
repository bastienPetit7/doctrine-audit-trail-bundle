<?php

declare(strict_types=1);

use Metadev\AuditLogBundle\Repository\AuditLogRepository;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set(AuditLogRepository::class);
};
