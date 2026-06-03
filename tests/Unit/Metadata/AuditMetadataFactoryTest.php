<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Metadata;

use Metadev\DoctrineAuditTrailBundle\Metadata\AuditMetadataFactory;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Entity\AuditedDummy;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Entity\PlainDummy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditMetadataFactoryTest extends TestCase
{
    #[Test]
    public function it_should_mark_a_class_as_auditable_when_it_carries_the_attribute(): void
    {
        $factory = new AuditMetadataFactory();

        $metadata = $factory->getMetadata(AuditedDummy::class);

        self::assertTrue($metadata->auditable);
        self::assertSame('Dummy', $metadata->label);
    }

    #[Test]
    public function it_should_ignore_properties_marked_with_audit_ignore(): void
    {
        $factory = new AuditMetadataFactory();

        $metadata = $factory->getMetadata(AuditedDummy::class);

        self::assertTrue($metadata->isFieldIgnored('password'));
        self::assertFalse($metadata->isFieldIgnored('title'));
    }

    #[Test]
    public function it_should_merge_globally_ignored_fields(): void
    {
        $factory = new AuditMetadataFactory(['title']);

        $metadata = $factory->getMetadata(AuditedDummy::class);

        self::assertTrue($metadata->isFieldIgnored('title'));
        self::assertTrue($metadata->isFieldIgnored('password'));
    }

    #[Test]
    public function it_should_treat_a_bare_class_as_not_auditable(): void
    {
        $factory = new AuditMetadataFactory();

        $metadata = $factory->getMetadata(PlainDummy::class);

        self::assertFalse($metadata->auditable);
        self::assertSame([], $metadata->ignoredFields);
    }

    #[Test]
    public function it_should_accept_an_object_instance(): void
    {
        $factory = new AuditMetadataFactory();

        self::assertTrue($factory->isAuditable(new AuditedDummy()));
        self::assertFalse($factory->isAuditable(new PlainDummy()));
    }
}
