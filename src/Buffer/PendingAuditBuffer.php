<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Buffer;

final class PendingAuditBuffer
{
    /** @var array<int, PendingAudit> spl_object_id => entry */
    private array $entries = [];

    public function add(PendingAudit $pending): void
    {
        $this->entries[spl_object_id($pending->entity)] = $pending;
    }

    public function get(object $entity): ?PendingAudit
    {
        return $this->entries[spl_object_id($entity)] ?? null;
    }

    /**
     * @return list<PendingAudit>
     */
    public function all(): array
    {
        return array_values($this->entries);
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
