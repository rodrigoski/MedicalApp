<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Estado logico de un recurso (activo / inactivo).
 *
 * Se modela aparte de `deleted_at` porque son conceptos distintos:
 *   - inactivo : el registro sigue existiendo y es auditable, pero no opera.
 *   - eliminado : borrado logico, no aparece en listados ni se puede agendar.
 */
enum ResourceStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activo',
            self::INACTIVE => 'Inactivo',
        };
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
