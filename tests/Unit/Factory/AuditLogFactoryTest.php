<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Tests\Unit\Factory;

use Metadev\AuditLogBundle\Enum\AuditAction;
use Metadev\AuditLogBundle\Factory\AuditLogFactory;
use Metadev\AuditLogBundle\Tests\Fixtures\Entity\AuditedDummy;
use Metadev\AuditLogBundle\User\AuditActor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditLogFactoryTest extends TestCase
{
    #[Test]
    public function it_should_build_an_entry_from_a_single_identifier_and_actor(): void
    {
        $actor = new AuditActor(
            label: 'jane',
            userId: '42',
            userIdentifier: 'jane',
            ipAddress: '203.0.113.7',
            userAgent: 'PHPUnit',
        );

        $log = (new AuditLogFactory())->create(
            new AuditedDummy(),
            AuditAction::Update,
            ['before' => ['title' => 'a'], 'after' => ['title' => 'b']],
            $actor,
            ['id' => 7],
        );

        self::assertSame(AuditedDummy::class, $log->getEntityClass());
        self::assertSame('7', $log->getEntityId());
        self::assertSame(AuditAction::Update, $log->getAction());
        self::assertSame(['before' => ['title' => 'a'], 'after' => ['title' => 'b']], $log->getDiff());
        self::assertSame('42', $log->getUserId());
        self::assertSame('jane', $log->getUserIdentifier());
        self::assertSame('203.0.113.7', $log->getIpAddress());
        self::assertSame('PHPUnit', $log->getUserAgent());
        self::assertSame('jane', $log->getActorLabel());
    }

    #[Test]
    public function it_should_encode_a_composite_identifier_as_json(): void
    {
        $log = (new AuditLogFactory())->create(
            new AuditedDummy(),
            AuditAction::Create,
            ['after' => []],
            new AuditActor(label: 'cli'),
            ['postId' => 3, 'tagId' => 9],
        );

        self::assertSame('{"postId":3,"tagId":9}', $log->getEntityId());
    }
}
