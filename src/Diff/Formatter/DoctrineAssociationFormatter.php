<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Diff\Formatter;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;

final class DoctrineAssociationFormatter implements ValueFormatterInterface
{
    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function supports(mixed $value): bool
    {
        if (!\is_object($value)) {
            return false;
        }

        if ($value instanceof \DateTimeInterface || $value instanceof \BackedEnum) {
            return false;
        }

        return null !== $this->registry->getManagerForClass($value::class);
    }

    /**
     * @return array{class: class-string, id: mixed}
     */
    public function format(mixed $value): array
    {
        \assert(\is_object($value));

        $manager = $this->registry->getManagerForClass($value::class);
        \assert(null !== $manager);

        /** @var ClassMetadata<object> $metadata */
        $metadata = $manager->getClassMetadata($value::class);

        return [
            'class' => $metadata->getName(),
            'id' => $this->normaliseIdentifier(
                $metadata->getIdentifierValues($value),
                \count($metadata->getIdentifier()),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $identifiers
     */
    private function normaliseIdentifier(array $identifiers, int $identifierCardinality): mixed
    {
        if (1 === $identifierCardinality) {
            return [] === $identifiers ? null : reset($identifiers);
        }

        return $identifiers;
    }
}
