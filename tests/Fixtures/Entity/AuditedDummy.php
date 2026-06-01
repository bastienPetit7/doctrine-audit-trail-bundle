<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Tests\Fixtures\Entity;

use Metadev\AuditLogBundle\Attribute\Auditable;
use Metadev\AuditLogBundle\Attribute\AuditIgnore;

#[Auditable(label: 'Dummy')]
class AuditedDummy
{
    public int $id = 0;

    public string $title = '';

    #[AuditIgnore]
    public string $password = '';
}
