<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Doctrine;

use Doctrine\ORM\Mapping as ORM;
use Metadev\DoctrineAuditTrailBundle\Attribute\Auditable;
use Metadev\DoctrineAuditTrailBundle\Attribute\AuditIgnore;

#[ORM\Entity]
#[ORM\Table(name: 'audit_order')]
#[Auditable(label: 'Order')]
class AuditedOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $reference = '';

    #[ORM\Embedded(class: Money::class)]
    public Money $price;

    #[ORM\Embedded(class: Credentials::class)]
    #[AuditIgnore]
    public Credentials $providerCreds;

    #[ORM\Embedded(class: Credentials::class, columnPrefix: 'exposed_')]
    public Credentials $exposedCreds;

    public function __construct()
    {
        $this->price = new Money();
        $this->providerCreds = new Credentials();
        $this->exposedCreds = new Credentials();
    }
}
