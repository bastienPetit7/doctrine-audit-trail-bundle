<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Entity;

use Metadev\DoctrineAuditTrailBundle\Attribute\Auditable;
use Metadev\DoctrineAuditTrailBundle\Attribute\AuditIgnore;

#[Auditable(label: 'Dummy')]
class AuditedDummy
{
    public int $id = 0;

    public string $title = '';

    #[AuditIgnore]
    public string $password = '';
}
