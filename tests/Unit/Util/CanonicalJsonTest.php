<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Util;

use Metadev\DoctrineAuditTrailBundle\Util\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonTest extends TestCase
{
    #[Test]
    public function it_should_encode_with_recursively_sorted_keys(): void
    {
        $encoded = CanonicalJson::encode(['z' => 1, 'nested' => ['b' => 2, 'a' => 3]]);

        self::assertSame('{"nested":{"a":3,"b":2},"z":1}', $encoded);
    }

    #[Test]
    public function it_should_produce_identical_output_regardless_of_input_key_order(): void
    {
        $a = CanonicalJson::encode(['title' => 'Gone', 'price' => 100]);
        $b = CanonicalJson::encode(['price' => 100, 'title' => 'Gone']);

        self::assertSame($a, $b);
    }
}
