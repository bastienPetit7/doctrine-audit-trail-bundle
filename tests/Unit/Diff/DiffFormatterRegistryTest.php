<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Diff;

use Metadev\DoctrineAuditTrailBundle\Diff\DiffFormatterRegistry;
use Metadev\DoctrineAuditTrailBundle\Diff\Formatter\ScalarValueFormatter;
use Metadev\DoctrineAuditTrailBundle\Diff\Formatter\ValueFormatterInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DiffFormatterRegistryTest extends TestCase
{
    #[Test]
    public function it_should_use_the_first_supporting_formatter(): void
    {
        $custom = new class implements ValueFormatterInterface {
            public function supports(mixed $value): bool
            {
                return $value instanceof \DateTimeInterface;
            }

            public function format(mixed $value): mixed
            {
                return 'CUSTOM';
            }
        };

        // Custom formatter listed first, so it wins over the scalar one for dates.
        $registry = new DiffFormatterRegistry([$custom, new ScalarValueFormatter()]);

        self::assertSame('CUSTOM', $registry->format(new \DateTimeImmutable()));
        self::assertSame('plain', $registry->format('plain'));
    }

    #[Test]
    public function it_should_return_the_raw_value_when_no_formatter_supports_it(): void
    {
        $registry = new DiffFormatterRegistry([]);

        $value = ['nested' => ['array' => true]];

        self::assertSame($value, $registry->format($value));
    }
}
