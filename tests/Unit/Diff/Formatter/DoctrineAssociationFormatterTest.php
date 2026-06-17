<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Unit\Diff\Formatter;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Metadev\DoctrineAuditTrailBundle\Diff\Formatter\DoctrineAssociationFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DoctrineAssociationFormatterTest extends TestCase
{
    #[Test]
    public function it_should_not_support_scalar_values(): void
    {
        $formatter = new DoctrineAssociationFormatter($this->registryWith([]));

        self::assertFalse($formatter->supports(null));
        self::assertFalse($formatter->supports(42));
        self::assertFalse($formatter->supports('foo'));
        self::assertFalse($formatter->supports(true));
        self::assertFalse($formatter->supports(['a' => 1]));
    }

    #[Test]
    public function it_should_not_support_dates_and_enums_even_when_the_registry_would_resolve_them(): void
    {
        // Even a permissive registry must not pull DateTime/Enums into the
        // association branch — they belong to the scalar formatter.
        $formatter = new DoctrineAssociationFormatter($this->permissiveRegistry());

        self::assertFalse($formatter->supports(new \DateTimeImmutable()));
        self::assertFalse($formatter->supports(StubBackedEnum::Foo));
    }

    #[Test]
    public function it_should_not_support_a_non_managed_object(): void
    {
        $formatter = new DoctrineAssociationFormatter($this->registryWith([]));

        self::assertFalse($formatter->supports(new \stdClass()));
    }

    #[Test]
    public function it_should_support_a_managed_entity(): void
    {
        $entity = new StubEntity();
        $formatter = new DoctrineAssociationFormatter($this->registryWith([$entity::class => $this->stubManager($entity::class, ['id' => 5])]));

        self::assertTrue($formatter->supports($entity));
    }

    #[Test]
    public function it_should_format_an_entity_with_a_simple_identifier(): void
    {
        $entity = new StubEntity();
        $formatter = new DoctrineAssociationFormatter($this->registryWith([
            $entity::class => $this->stubManager($entity::class, ['id' => 5]),
        ]));

        self::assertSame(
            ['class' => StubEntity::class, 'id' => 5],
            $formatter->format($entity),
        );
    }

    #[Test]
    public function it_should_format_an_entity_with_a_composite_identifier(): void
    {
        $entity = new StubEntity();
        $formatter = new DoctrineAssociationFormatter($this->registryWith([
            $entity::class => $this->stubManager($entity::class, ['tenant' => 'acme', 'ref' => 42]),
        ]));

        self::assertSame(
            ['class' => StubEntity::class, 'id' => ['tenant' => 'acme', 'ref' => 42]],
            $formatter->format($entity),
        );
    }

    #[Test]
    public function it_should_format_an_entity_with_no_identifier_yet_as_null(): void
    {
        $entity = new StubEntity();
        $formatter = new DoctrineAssociationFormatter($this->registryWith([
            $entity::class => $this->stubManager($entity::class, [], ['id']),
        ]));

        self::assertSame(
            ['class' => StubEntity::class, 'id' => null],
            $formatter->format($entity),
        );
    }

    #[Test]
    public function it_should_keep_the_map_shape_for_a_composite_key_partially_populated(): void
    {
        $entity = new StubEntity();
        $formatter = new DoctrineAssociationFormatter($this->registryWith([
            $entity::class => $this->stubManager(
                $entity::class,
                ['tenant' => 'acme'],
                ['tenant', 'ref'],
            ),
        ]));

        self::assertSame(
            ['class' => StubEntity::class, 'id' => ['tenant' => 'acme']],
            $formatter->format($entity),
        );
    }

    #[Test]
    public function it_should_format_a_stringable_managed_entity_with_its_id_not_its_string(): void
    {
        $entity = new StubStringableEntity();
        $formatter = new DoctrineAssociationFormatter($this->registryWith([
            $entity::class => $this->stubManager($entity::class, ['id' => 7]),
        ]));

        self::assertTrue($formatter->supports($entity));
        self::assertSame(
            ['class' => StubStringableEntity::class, 'id' => 7],
            $formatter->format($entity),
        );
    }

    /**
     * @param array<class-string, ObjectManager> $managers indexed by managed class name
     */
    private function registryWith(array $managers): ManagerRegistry
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(
            static fn (string $class): ?ObjectManager => $managers[$class] ?? null,
        );

        return $registry;
    }

    private function permissiveRegistry(): ManagerRegistry
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(
            fn (string $class): ObjectManager => $this->stubManager($class, ['id' => 1]),
        );

        return $registry;
    }

    /**
     * @param array<string, mixed> $identifiers
     * @param list<string>|null    $identifierFields field names defining the PK (defaults to keys of $identifiers when omitted)
     */
    private function stubManager(string $class, array $identifiers, ?array $identifierFields = null): ObjectManager
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn($class);
        $metadata->method('getIdentifierValues')->willReturn($identifiers);
        $metadata->method('getIdentifier')->willReturn($identifierFields ?? array_keys($identifiers));

        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getClassMetadata')->with($class)->willReturn($metadata);

        return $manager;
    }
}

final class StubEntity
{
}

final class StubStringableEntity implements \Stringable
{
    public function __toString(): string
    {
        return 'I-am-a-label';
    }
}

enum StubBackedEnum: string
{
    case Foo = 'foo';
}
