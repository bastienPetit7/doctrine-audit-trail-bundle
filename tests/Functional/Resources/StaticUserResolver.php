<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Functional\Resources;

use Metadev\DoctrineAuditTrailBundle\User\AuditActor;
use Metadev\DoctrineAuditTrailBundle\User\AuditUserResolverInterface;

final class StaticUserResolver implements AuditUserResolverInterface
{
    public function resolve(): AuditActor
    {
        return new AuditActor(label: 'static-test-user', userIdentifier: 'static-test-user');
    }
}
