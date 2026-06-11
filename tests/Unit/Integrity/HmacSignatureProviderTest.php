<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Integrity;

use Metadev\DoctrineAuditTrailBundle\Integrity\HmacSignatureProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HmacSignatureProviderTest extends TestCase
{
    #[Test]
    public function it_should_be_deterministic_for_the_same_payload(): void
    {
        $provider = new HmacSignatureProvider('top-secret');

        self::assertSame($provider->sign('payload'), $provider->sign('payload'));
    }

    #[Test]
    public function it_should_produce_a_different_signature_for_a_different_secret(): void
    {
        $a = new HmacSignatureProvider('secret-a');
        $b = new HmacSignatureProvider('secret-b');

        self::assertNotSame($a->sign('payload'), $b->sign('payload'));
    }

    #[Test]
    public function it_should_produce_a_sha256_hex_signature(): void
    {
        $signature = (new HmacSignatureProvider('top-secret'))->sign('payload');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
    }
}
