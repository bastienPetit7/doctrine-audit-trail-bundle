<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Diff\Formatter;

final class ScalarValueFormatter implements ValueFormatterInterface
{
    public function supports(mixed $value): bool
    {
        return null === $value
            || \is_scalar($value)
            || $value instanceof \DateTimeInterface
            || $value instanceof \BackedEnum
            || $value instanceof \Stringable;
    }

    public function format(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \Stringable => (string) $value,
            default => $value,
        };
    }
}
