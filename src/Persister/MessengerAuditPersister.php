<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Persister;

use Metadev\DoctrineAuditTrailBundle\Messenger\PersistAuditTrailEntries;
use Metadev\DoctrineAuditTrailBundle\Persister\Exception\AuditDispatchFailedException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

final class MessengerAuditPersister implements AuditPersisterInterface
{
    public const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {
        if ($this->batchSize < 1) {
            throw new \InvalidArgumentException(\sprintf('Audit batch size must be >= 1, got %d.', $this->batchSize));
        }
    }

    public function persist(iterable $auditLogs): void
    {
        $entries = $auditLogs instanceof \Traversable
            ? iterator_to_array($auditLogs, false)
            : array_values($auditLogs);

        if ([] === $entries) {
            return;
        }

        $totalEntries = \count($entries);
        $failedEntries = 0;
        $firstError = null;

        foreach (array_chunk($entries, $this->batchSize) as $chunk) {
            try {
                $this->messageBus->dispatch(
                    new Envelope(new PersistAuditTrailEntries($chunk), [new DispatchAfterCurrentBusStamp()]),
                );
            } catch (\Throwable $exception) {
                $failedEntries += \count($chunk);
                $firstError ??= $exception;
            }
        }

        if (null !== $firstError) {
            throw new AuditDispatchFailedException($failedEntries, $totalEntries, $firstError);
        }
    }
}
