<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Metadata;

use Doctrine\Persistence\Proxy;
use Metadev\AuditLogBundle\Attribute\Auditable;
use Metadev\AuditLogBundle\Attribute\AuditIgnore;

final class AuditMetadataFactory
{
    /** @var array<class-string, AuditMetadata> */
    private array $cache = [];

    /** @var array<string, true> */
    private readonly array $globallyIgnoredFields;

    /**
     * @param list<string> $globallyIgnoredFields
     */
    public function __construct(array $globallyIgnoredFields = [])
    {
        $this->globallyIgnoredFields = array_fill_keys($globallyIgnoredFields, true);
    }

    /**
     * @param class-string|object $classOrObject
     */
    public function getMetadata(string|object $classOrObject): AuditMetadata
    {
        $class = $this->resolveRealClass($classOrObject);

        return $this->cache[$class] ??= $this->build($class);
    }

    /**
     * @param class-string|object $classOrObject
     */
    public function isAuditable(string|object $classOrObject): bool
    {
        return $this->getMetadata($classOrObject)->auditable;
    }

    /**
     * @param class-string $class
     */
    private function build(string $class): AuditMetadata
    {
        $reflection = new \ReflectionClass($class);

        $auditableAttributes = $reflection->getAttributes(Auditable::class);
        if ([] === $auditableAttributes) {
            return new AuditMetadata(auditable: false);
        }

        /** @var Auditable $auditable */
        $auditable = $auditableAttributes[0]->newInstance();

        $ignored = $this->globallyIgnoredFields;
        foreach ($this->collectProperties($reflection) as $property) {
            if ([] !== $property->getAttributes(AuditIgnore::class)) {
                $ignored[$property->getName()] = true;
            }
        }

        return new AuditMetadata(
            auditable: true,
            ignoredFields: $ignored,
            label: $auditable->label,
        );
    }

    /**
     * @return iterable<\ReflectionProperty>
     */
    private function collectProperties(\ReflectionClass $reflection): iterable
    {
        // Walk the hierarchy so #[AuditIgnore] on inherited properties is honoured.
        for ($current = $reflection; false !== $current; $current = $current->getParentClass()) {
            yield from $current->getProperties();
        }
    }

    /**
     * @param class-string|object $classOrObject
     *
     * @return class-string
     */
    private function resolveRealClass(string|object $classOrObject): string
    {
        $class = \is_object($classOrObject) ? $classOrObject::class : $classOrObject;

        if (is_subclass_of($class, Proxy::class)) {
            $parent = get_parent_class($class);
            if (false !== $parent) {
                return $parent;
            }
        }

        return $class;
    }
}
