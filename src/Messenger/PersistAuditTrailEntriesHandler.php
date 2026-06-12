<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Messenger;

use Metadev\DoctrineAuditTrailBundle\Persister\DoctrineAuditPersister;

final class PersistAuditTrailEntriesHandler
{
    /**
     * Hard-typed against DoctrineAuditPersister (not AuditPersisterInterface)
     * on purpose:
     *  1. In async mode the interface alias resolves to MessengerAuditPersister,
     *     so autowiring it here would make the worker re-dispatch the same
     *     PersistAuditTrailEntries message and loop until the DLQ overflows.
     *  2. With soft_fail enabled the interface resolves to SoftFailAuditPersister,
     *     which would swallow worker exceptions and ACK the message — defeating
     *     Symfony Messenger's retry/DLQ semantics that we rely on for durability
     *     once the message has reached the broker.
     */
    public function __construct(
        private readonly DoctrineAuditPersister $persister,
    ) {
    }

    public function __invoke(PersistAuditTrailEntries $message): void
    {
        $this->persister->persist($message->entries);
    }
}
