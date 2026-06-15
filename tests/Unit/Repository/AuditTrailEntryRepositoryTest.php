<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Repository;

use Metadev\DoctrineAuditTrailBundle\Repository\AuditTrailEntryRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditTrailEntryRepositoryTest extends TestCase
{
    #[Test]
    public function it_should_clamp_a_huge_limit_down_to_the_max_page_size(): void
    {
        self::assertSame(
            AuditTrailEntryRepository::MAX_PAGE_SIZE,
            AuditTrailEntryRepository::cappedLimit(\PHP_INT_MAX),
        );
    }

    #[Test]
    public function it_should_clamp_a_non_positive_limit_up_to_one(): void
    {
        self::assertSame(1, AuditTrailEntryRepository::cappedLimit(0));
        self::assertSame(1, AuditTrailEntryRepository::cappedLimit(-5));
    }

    #[Test]
    public function it_should_pass_through_a_reasonable_limit_unchanged(): void
    {
        self::assertSame(50, AuditTrailEntryRepository::cappedLimit(50));
    }
}
