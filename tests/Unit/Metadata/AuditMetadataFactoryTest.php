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
    public function it_should_ignore_built_in_blacklisted_fields_by_default(): void
    {
        $factory = new AuditMetadataFactory();

        $metadata = $factory->getMetadata(AuditedDummy::class);

        self::assertTrue($metadata->isFieldIgnored('apiKey'));
        self::assertTrue($metadata->isFieldIgnored('refreshToken'));
        self::assertFalse($metadata->isFieldIgnored('title'));
    }

    #[Test]
    public function it_should_blacklist_banking_and_mfa_field_names_by_default(): void
    {
        $factory = new AuditMetadataFactory();

        $metadata = $factory->getMetadata(AuditedDummy::class);

        foreach (['iban', 'bic', 'swift', 'pan', 'panMasked', 'cardNumber', 'cardCvv', 'cardPin', 'cvv', 'passwordHash', 'legacyPasswordHash', 'mfaSecret', 'totpSecret', 'recoveryCode'] as $field) {
            self::assertTrue($metadata->isFieldIgnored($field), \sprintf('"%s" should be blacklisted by default', $field));
        }
    }

    #[Test]
    public function it_should_keep_the_blacklist_when_user_adds_custom_ignored_fields(): void
    {
        $factory = new AuditMetadataFactory(['title']);

        $metadata = $factory->getMetadata(AuditedDummy::class);

        self::assertTrue($metadata->isFieldIgnored('title'));
        self::assertTrue($metadata->isFieldIgnored('apiKey'));
    }

    #[Test]
    public function it_should_re_enable_a_blacklisted_field_when_listed_in_force_audit_fields(): void
    {
        $factory = new AuditMetadataFactory(forceAuditFields: ['refreshToken']);

        $metadata = $factory->getMetadata(AuditedDummy::class);

        self::assertFalse($metadata->isFieldIgnored('refreshToken'));
        self::assertTrue($metadata->isFieldIgnored('apiKey'));
    }

    #[Test]
    public function it_should_keep_audit_ignore_precedence_over_force_audit_fields(): void
    {
        $factory = new AuditMetadataFactory(forceAuditFields: ['password']);

        $metadata = $factory->getMetadata(AuditedDummy::class);

        // #[AuditIgnore] on AuditedDummy::$password wins over the global escape hatch.
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

    #[Test]
    public function it_should_ignore_embedded_subfields_when_the_parent_property_is_ignored(): void
    {
        $factory = new AuditMetadataFactory();

        $metadata = $factory->getMetadata(AuditedDummy::class);

        self::assertTrue($metadata->isFieldIgnored('password.hash'));
        self::assertTrue($metadata->isFieldIgnored('password.salt'));
    }

    #[Test]
    public function it_should_apply_the_default_deny_list_to_embedded_subfields(): void
    {
        $factory = new AuditMetadataFactory();

        $metadata = $factory->getMetadata(AuditedDummy::class);

        self::assertTrue($metadata->isFieldIgnored('apiKey.value'));
        self::assertTrue($metadata->isFieldIgnored('refreshToken.exp'));
    }

    #[Test]
    public function it_should_not_ignore_unrelated_dotted_fields(): void
    {
        $factory = new AuditMetadataFactory();

        $metadata = $factory->getMetadata(AuditedDummy::class);

        self::assertFalse($metadata->isFieldIgnored('price.amount'));
        self::assertFalse($metadata->isFieldIgnored('title.localised'));
    }
}
