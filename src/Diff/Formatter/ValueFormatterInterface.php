<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Diff\Formatter;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('audit_log.value_formatter')]
interface ValueFormatterInterface
{
    public function supports(mixed $value): bool;

    public function format(mixed $value): mixed;
}
