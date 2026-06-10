<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Metadata;

use Doctrine\Persistence\Proxy;
use Metadev\DoctrineAuditTrailBundle\Attribute\Auditable;
use Metadev\DoctrineAuditTrailBundle\Attribute\AuditIgnore;

final class AuditMetadataFactory
{
    /**
     * Built-in security blacklist: field names never recorded in a diff by default.
     * Merged with the user-defined ignored fields; opt back in via $forceAuditFields.
     *
     * @var list<string>
     */
    public const DEFAULT_IGNORED_FIELDS = [
        'password',
        'plainPassword',
        'apiKey',
        'apiToken',
        'accessToken',
        'refreshToken',
        'secret',
        'token',
        'salt',
        'pin',
        'cvv',
    ];

    /** @var array<class-string, AuditMetadata> */
    private array $cache = [];

    /** @var array<string, true> */
    private readonly array $globallyIgnoredFields;

    /**
     * @param list<string> $ignoredFields    User-defined fields to ignore, merged on top of the built-in blacklist
     * @param list<string> $forceAuditFields Escape hatch: fields audited even if blacklisted by default
     */
    public function __construct(array $ignoredFields = [], array $forceAuditFields = [])
    {
        $ignored = array_fill_keys([...self::DEFAULT_IGNORED_FIELDS, ...$ignoredFields], true);

        foreach ($forceAuditFields as $field) {
            unset($ignored[$field]);
        }

        $this->globallyIgnoredFields = $ignored;
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
