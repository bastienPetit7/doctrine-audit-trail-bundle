<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\User;

use Metadev\DoctrineAuditTrailBundle\User\AuditActor;
use Metadev\DoctrineAuditTrailBundle\User\AuditContextHolder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditContextHolderTest extends TestCase
{
    #[Test]
    public function it_should_be_empty_by_default(): void
    {
        self::assertNull((new AuditContextHolder())->getActor());
    }

    #[Test]
    public function it_should_store_and_reset_the_overridden_actor(): void
    {
        $holder = new AuditContextHolder();
        $actor = new AuditActor(label: 'batch-nightly');

        $holder->setActor($actor);
        self::assertSame($actor, $holder->getActor());

        $holder->reset();
        self::assertNull($holder->getActor());
    }
}
