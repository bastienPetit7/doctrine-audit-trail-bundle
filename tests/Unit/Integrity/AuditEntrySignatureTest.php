<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Integrity;

use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Integrity\AuditEntrySignature;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditEntrySignatureTest extends TestCase
{
    private const CREATED_AT = '2026-06-10T12:00:00+00:00';

    /**
     * @param array{before: array<string, mixed>, after: array<string, mixed>} $diff
     */
    private function payload(array $diff, ?string $userIdentifier = 'jane', string $createdAt = self::CREATED_AT): string
    {
        return AuditEntrySignature::payload(
            'App\\Entity\\Order',
            '42',
            AuditAction::Update,
            $diff,
            '7',
            $userIdentifier,
            '203.0.113.7',
            'PHPUnit',
            'jane',
            new \DateTimeImmutable($createdAt),
        );
    }

    #[Test]
    public function it_should_be_identical_for_identical_inputs(): void
    {
        $diff = ['before' => ['title' => 'a'], 'after' => ['title' => 'b']];

        self::assertSame($this->payload($diff), $this->payload($diff));
    }

    #[Test]
    public function it_should_be_stable_regardless_of_diff_key_order(): void
    {
        $ordered = ['before' => ['name' => 'x', 'age' => 1], 'after' => []];
        $shuffled = ['after' => [], 'before' => ['age' => 1, 'name' => 'x']];

        self::assertSame($this->payload($ordered), $this->payload($shuffled));
    }

    #[Test]
    public function it_should_change_when_the_diff_changes(): void
    {
        $a = $this->payload(['before' => ['title' => 'a'], 'after' => ['title' => 'b']]);
        $b = $this->payload(['before' => ['title' => 'a'], 'after' => ['title' => 'TAMPERED']]);

        self::assertNotSame($a, $b);
    }

    #[Test]
    public function it_should_change_when_the_timestamp_is_backdated(): void
    {
        $diff = ['before' => [], 'after' => []];

        self::assertNotSame(
            $this->payload($diff, createdAt: self::CREATED_AT),
            $this->payload($diff, createdAt: '2020-01-01T00:00:00+00:00'),
        );
    }

    #[Test]
    public function it_should_change_when_the_actor_identifier_changes(): void
    {
        $diff = ['before' => [], 'after' => []];

        self::assertNotSame(
            $this->payload($diff, userIdentifier: 'jane'),
            $this->payload($diff, userIdentifier: 'mallory'),
        );
    }
}
