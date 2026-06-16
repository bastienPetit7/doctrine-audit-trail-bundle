<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Util;

final class CanonicalJson
{
    /**
     * Recursively sort keys, then JSON-encode with stable flags.
     *
     * @param array<array-key, mixed> $data
     */
    public static function encode(array $data): string
    {
        self::ksortRecursive($data);

        return json_encode(
            $data,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function ksortRecursive(array &$data): void
    {
        foreach ($data as &$value) {
            if (\is_array($value)) {
                self::ksortRecursive($value);
            }
        }
        unset($value);

        ksort($data);
    }
}
