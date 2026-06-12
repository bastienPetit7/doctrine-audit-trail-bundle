<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Persister;

use Metadev\DoctrineAuditTrailBundle\Messenger\PersistAuditTrailEntries;
use Metadev\DoctrineAuditTrailBundle\Persister\Exception\AuditDispatchFailedException;
use Metadev\DoctrineAuditTrailBundle\Persister\MessengerAuditPersister;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\AuditTrailEntryBuilder;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * @internal
 */
#[Small]
final class MessengerAuditPersisterTest extends TestCase
{
    #[Test]
    public function it_should_dispatch_a_message_carrying_the_entries(): void
    {
        $bus = $this->spyBus();
        $entry = AuditTrailEntryBuilder::make();

        (new MessengerAuditPersister($bus))->persist([$entry]);

        self::assertCount(1, $bus->dispatched);
        self::assertSame([$entry], $this->extractMessage($bus->dispatched[0])->entries);
    }

    #[Test]
    public function it_should_not_dispatch_when_there_is_nothing_to_persist(): void
    {
        $bus = $this->spyBus();

        (new MessengerAuditPersister($bus))->persist([]);

        self::assertSame([], $bus->dispatched);
    }

    #[Test]
    public function it_should_stamp_messages_with_dispatch_after_current_bus(): void
    {
        $bus = $this->spyBus();

        (new MessengerAuditPersister($bus))->persist([AuditTrailEntryBuilder::make()]);

        self::assertNotEmpty($bus->dispatched[0]->all(DispatchAfterCurrentBusStamp::class));
    }

    #[Test]
    public function it_should_chunk_entries_into_multiple_messages_when_the_batch_size_is_exceeded(): void
    {
        $bus = $this->spyBus();
        $entries = array_map(static fn () => AuditTrailEntryBuilder::make(), range(1, 5));

        (new MessengerAuditPersister($bus, batchSize: 2))->persist($entries);

        self::assertCount(3, $bus->dispatched);
        self::assertCount(2, $this->extractMessage($bus->dispatched[0])->entries);
        self::assertCount(2, $this->extractMessage($bus->dispatched[1])->entries);
        self::assertCount(1, $this->extractMessage($bus->dispatched[2])->entries);
    }

    #[Test]
    public function it_should_dispatch_a_single_message_when_entries_fit_in_one_batch(): void
    {
        $bus = $this->spyBus();
        $entries = [AuditTrailEntryBuilder::make(), AuditTrailEntryBuilder::make()];

        (new MessengerAuditPersister($bus, batchSize: 100))->persist($entries);

        self::assertCount(1, $bus->dispatched);
        self::assertSame($entries, $this->extractMessage($bus->dispatched[0])->entries);
    }

    #[Test]
    public function it_should_reject_a_non_positive_batch_size(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MessengerAuditPersister($this->spyBus(), batchSize: 0);
    }

    #[Test]
    public function it_should_continue_dispatching_remaining_chunks_when_one_fails(): void
    {
        $entries = array_map(static fn () => AuditTrailEntryBuilder::make(), range(1, 5));
        $bus = new class implements MessageBusInterface {
            public int $calls = 0;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ++$this->calls;

                if (1 === $this->calls) {
                    throw new \RuntimeException('broker unreachable');
                }

                return $message instanceof Envelope ? $message : new Envelope($message, $stamps);
            }
        };

        try {
            (new MessengerAuditPersister($bus, batchSize: 2))->persist($entries);
            self::fail('Expected AuditDispatchFailedException.');
        } catch (AuditDispatchFailedException $exception) {
            self::assertSame(3, $bus->calls, 'All three chunks must be attempted even when the first fails.');
            self::assertSame(2, $exception->failedEntries);
            self::assertSame(5, $exception->totalEntries);
        }
    }

    #[Test]
    public function it_should_aggregate_failures_across_chunks(): void
    {
        $entries = array_map(static fn () => AuditTrailEntryBuilder::make(), range(1, 4));
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('broker down');
            }
        };

        try {
            (new MessengerAuditPersister($bus, batchSize: 2))->persist($entries);
            self::fail('Expected AuditDispatchFailedException.');
        } catch (AuditDispatchFailedException $exception) {
            self::assertSame(4, $exception->failedEntries);
            self::assertSame(4, $exception->totalEntries);
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }
    }

    /**
     * @return MessageBusInterface&object{dispatched: list<Envelope>}
     */
    private function spyBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = $message instanceof Envelope ? $message : new Envelope($message, $stamps);
                $this->dispatched[] = $envelope;

                return $envelope;
            }
        };
    }

    private function extractMessage(Envelope $envelope): PersistAuditTrailEntries
    {
        $message = $envelope->getMessage();
        self::assertInstanceOf(PersistAuditTrailEntries::class, $message);

        return $message;
    }
}
