<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Tests\Fixtures\Doctrine;

use Doctrine\ORM\Mapping as ORM;
use Metadev\AuditLogBundle\Attribute\Auditable;
use Metadev\AuditLogBundle\Attribute\AuditIgnore;

#[ORM\Entity]
#[ORM\Table(name: 'product')]
#[Auditable(label: 'Product')]
class AuditedProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name = '';

    #[ORM\Column(type: 'integer')]
    public int $price = 0;

    #[ORM\Column(type: 'string')]
    #[AuditIgnore]
    public string $secret = '';
}
