<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Diff;

use Metadev\AuditLogBundle\Diff\Formatter\ValueFormatterInterface;

final class DiffFormatterRegistry
{
    /** @var iterable<ValueFormatterInterface> */
    private readonly iterable $formatters;

    /**
     * @param iterable<ValueFormatterInterface> $formatters
     */
    public function __construct(iterable $formatters = [])
    {
        $this->formatters = $formatters;
    }

    public function format(mixed $value): mixed
    {
        foreach ($this->formatters as $formatter) {
            if ($formatter->supports($value)) {
                return $formatter->format($value);
            }
        }

        return $value;
    }
}
