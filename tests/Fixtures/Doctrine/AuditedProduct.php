<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Doctrine;

use Doctrine\ORM\Mapping as ORM;
use Metadev\DoctrineAuditTrailBundle\Attribute\Auditable;
use Metadev\DoctrineAuditTrailBundle\Attribute\AuditIgnore;

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
