<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Enum;

enum AuditAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
