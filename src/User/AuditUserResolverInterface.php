<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\User;

interface AuditUserResolverInterface
{
    public function resolve(): AuditActor;
}
