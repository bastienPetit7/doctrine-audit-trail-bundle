<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Messenger;

use Metadev\DoctrineAuditTrailBundle\Messenger\PersistAuditTrailEntries;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\AuditTrailEntryBuilder;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

#[Small]
final class PersistAuditTrailEntriesTest extends TestCase
{
    #[Test]
    public function it_should_round_trip_through_the_messenger_php_serializer(): void
    {
        $serializer = new PhpSerializer();
        $message = new PersistAuditTrailEntries([AuditTrailEntryBuilder::make()]);

        $encoded = $serializer->encode(new Envelope($message));
        $decoded = $serializer->decode($encoded);

        self::assertSame(serialize($message), serialize($decoded->getMessage()));
    }
}
