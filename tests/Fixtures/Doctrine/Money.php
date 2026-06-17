<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Doctrine;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class Money
{
    #[ORM\Column(type: 'integer')]
    public int $amount = 0;

    #[ORM\Column(type: 'string', length: 3)]
    public string $currency = 'EUR';
}
