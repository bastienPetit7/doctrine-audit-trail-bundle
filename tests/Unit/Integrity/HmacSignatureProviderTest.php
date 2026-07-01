<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Integrity;

use Metadev\DoctrineAuditTrailBundle\Integrity\HmacSignatureProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HmacSignatureProviderTest extends TestCase
{
    private const SECRET_A = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const SECRET_B = 'ffffffffffffffffffffffffffffffff';

    #[Test]
    public function it_should_be_deterministic_for_the_same_payload(): void
    {
        $provider = new HmacSignatureProvider(self::SECRET_A);

        self::assertSame($provider->sign('payload'), $provider->sign('payload'));
    }

    #[Test]
    public function it_should_produce_a_different_signature_for_a_different_secret(): void
    {
        $a = new HmacSignatureProvider(self::SECRET_A);
        $b = new HmacSignatureProvider(self::SECRET_B);

        self::assertNotSame($a->sign('payload'), $b->sign('payload'));
    }

    #[Test]
    public function it_should_produce_a_sha256_hex_signature(): void
    {
        $signature = (new HmacSignatureProvider(self::SECRET_A))->sign('payload');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
    }

    #[Test]
    public function it_should_reject_a_secret_shorter_than_32_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 32 characters');

        new HmacSignatureProvider(str_repeat('a', 31));
    }

    #[Test]
    public function it_should_accept_a_secret_of_exactly_32_characters(): void
    {
        $provider = new HmacSignatureProvider(str_repeat('a', 32));

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $provider->sign('payload'));
    }
}
