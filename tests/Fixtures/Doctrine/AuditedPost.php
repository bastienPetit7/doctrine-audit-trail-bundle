<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Metadev\DoctrineAuditTrailBundle\Attribute\Auditable;
use Metadev\DoctrineAuditTrailBundle\Attribute\AuditIgnore;

#[ORM\Entity]
#[ORM\Table(name: 'post')]
#[Auditable(label: 'Post')]
class AuditedPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $title = '';

    /** @var Collection<int, AuditedTag> */
    #[ORM\ManyToMany(targetEntity: AuditedTag::class)]
    #[ORM\JoinTable(name: 'post_tag')]
    public Collection $tags;

    /** @var Collection<int, AuditedTag> */
    #[ORM\ManyToMany(targetEntity: AuditedTag::class)]
    #[ORM\JoinTable(name: 'post_secret_tag')]
    #[AuditIgnore]
    public Collection $secretTags;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $this->secretTags = new ArrayCollection();
    }
}
