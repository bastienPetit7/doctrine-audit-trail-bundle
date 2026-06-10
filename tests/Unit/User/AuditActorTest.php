<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\User;

use Metadev\DoctrineAuditTrailBundle\User\AuditActor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditActorTest extends TestCase
{
    private function actor(): AuditActor
    {
        return new AuditActor(
            label: 'alice@example.com',
            userId: '42',
            userIdentifier: 'alice@example.com',
            ipAddress: '192.168.1.42',
            userAgent: 'Mozilla/5.0',
        );
    }

    #[Test]
    public function it_should_return_a_copy_with_a_new_ip_address_leaving_the_original_untouched(): void
    {
        $original = $this->actor();

        $anonymised = $original->withIpAddress('192.168.1.0');

        self::assertNotSame($original, $anonymised);
        self::assertSame('192.168.1.0', $anonymised->ipAddress);
        self::assertSame('192.168.1.42', $original->ipAddress);
        self::assertSame($original->userIdentifier, $anonymised->userIdentifier);
        self::assertSame($original->userAgent, $anonymised->userAgent);
        self::assertSame($original->userId, $anonymised->userId);
        self::assertSame($original->label, $anonymised->label);
    }

    #[Test]
    public function it_should_return_a_copy_with_a_new_user_identifier(): void
    {
        $original = $this->actor();

        $pseudonymised = $original->withUserIdentifier(hash('sha256', 'alice@example.com'));

        self::assertSame(hash('sha256', 'alice@example.com'), $pseudonymised->userIdentifier);
        self::assertSame('alice@example.com', $original->userIdentifier);
        self::assertSame($original->ipAddress, $pseudonymised->ipAddress);
    }

    #[Test]
    public function it_should_return_a_copy_with_a_new_user_agent(): void
    {
        $original = $this->actor();

        $stripped = $original->withUserAgent(null);

        self::assertNull($stripped->userAgent);
        self::assertSame('Mozilla/5.0', $original->userAgent);
        self::assertSame($original->ipAddress, $stripped->ipAddress);
    }

    #[Test]
    public function it_should_chain_the_immutable_copy_helpers(): void
    {
        $actor = $this->actor()
            ->withIpAddress('192.168.1.0')
            ->withUserIdentifier('hashed')
            ->withUserAgent(null);

        self::assertSame('192.168.1.0', $actor->ipAddress);
        self::assertSame('hashed', $actor->userIdentifier);
        self::assertNull($actor->userAgent);
        self::assertSame('42', $actor->userId);
        self::assertSame('alice@example.com', $actor->label);
    }
}
