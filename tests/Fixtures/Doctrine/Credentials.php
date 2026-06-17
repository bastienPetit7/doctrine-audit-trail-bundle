<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Doctrine;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class Credentials
{
    #[ORM\Column(type: 'string')]
    public string $login = '';

    #[ORM\Column(type: 'string')]
    public string $secret = '';

    #[ORM\Column(type: 'string')]
    public string $apiKey = '';
}
