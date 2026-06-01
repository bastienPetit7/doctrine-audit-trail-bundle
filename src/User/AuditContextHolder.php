<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\User;

final class AuditContextHolder
{
    private ?AuditActor $actor = null;

    public function setActor(AuditActor $actor): void
    {
        $this->actor = $actor;
    }

    public function getActor(): ?AuditActor
    {
        return $this->actor;
    }

    public function reset(): void
    {
        $this->actor = null;
    }
}
