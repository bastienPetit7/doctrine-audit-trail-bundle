<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\User;

interface AuditUserResolverInterface
{
    public function resolve(): AuditActor;
}
