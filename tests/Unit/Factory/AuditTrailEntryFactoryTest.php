<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Factory;

use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Factory\AuditTrailEntryFactory;
use Metadev\DoctrineAuditTrailBundle\Integrity\AuditEntrySignature;
use Metadev\DoctrineAuditTrailBundle\Integrity\HmacSignatureProvider;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Entity\AuditedDummy;
use Metadev\DoctrineAuditTrailBundle\User\AuditActor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditTrailEntryFactoryTest extends TestCase
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

        $log = (new AuditTrailEntryFactory())->create(
            new AuditedDummy(),
            AuditAction::Update,
            ['before' => ['title' => 'a'], 'after' => ['title' => 'b']],
            $actor,
            ['id' => 7],
        );

        self::assertSame(AuditedDummy::class, $log->getEntityClass());
        self::assertSame('7', $log->getEntityId());
        self::assertNull($log->getEntityLabel());
        self::assertSame(AuditAction::Update, $log->getAction());
        self::assertSame(['before' => ['title' => 'a'], 'after' => ['title' => 'b']], $log->getDiff());
        self::assertSame('42', $log->getUserId());
        self::assertSame('jane', $log->getUserIdentifier());
        self::assertSame('203.0.113.7', $log->getIpAddress());
        self::assertSame('PHPUnit', $log->getUserAgent());
        self::assertSame('jane', $log->getActorLabel());
    }

    #[Test]
    public function it_should_carry_the_entity_label_into_the_trail_entry(): void
    {
        $log = (new AuditTrailEntryFactory())->create(
            new AuditedDummy(),
            AuditAction::Create,
            ['before' => [], 'after' => []],
            new AuditActor(label: 'cli'),
            ['id' => 1],
            'Dummy',
        );

        self::assertSame('Dummy', $log->getEntityLabel());
    }

    #[Test]
    public function it_should_leave_the_signature_null_when_no_provider_is_configured(): void
    {
        $log = (new AuditTrailEntryFactory())->create(
            new AuditedDummy(),
            AuditAction::Create,
            ['before' => [], 'after' => []],
            new AuditActor(label: 'cli'),
            ['id' => 1],
        );

        self::assertNull($log->getSignature());
    }

    #[Test]
    public function it_should_seal_the_entry_with_a_verifiable_signature_when_a_provider_is_configured(): void
    {
        $provider = new HmacSignatureProvider('a1b2c3d4e5f60718293a4b5c6d7e8f90');

        $log = (new AuditTrailEntryFactory($provider))->create(
            new AuditedDummy(),
            AuditAction::Update,
            ['before' => ['title' => 'a'], 'after' => ['title' => 'b']],
            new AuditActor(label: 'jane', userIdentifier: 'jane'),
            ['id' => 7],
        );

        self::assertNotNull($log->getSignature());
        self::assertSame(
            $provider->sign(AuditEntrySignature::payloadFor($log)),
            $log->getSignature(),
        );
    }

    #[Test]
    public function it_should_encode_a_composite_identifier_as_json(): void
    {
        $log = (new AuditTrailEntryFactory())->create(
            new AuditedDummy(),
            AuditAction::Create,
            ['before' => [], 'after' => []],
            new AuditActor(label: 'cli'),
            ['postId' => 3, 'tagId' => 9],
        );

        self::assertSame('{"postId":3,"tagId":9}', $log->getEntityId());
    }
}
