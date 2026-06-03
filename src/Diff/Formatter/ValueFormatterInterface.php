<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Diff\Formatter;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('doctrine_audit_trail.value_formatter')]
interface ValueFormatterInterface
{
    public function supports(mixed $value): bool;

    public function format(mixed $value): mixed;
}
