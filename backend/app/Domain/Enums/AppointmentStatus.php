<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Estados posibles de una Cita.
 *
 * Este enum ES el modelo de estado exigido por la especificacion. La logica de
 * transicion vive en el agregado Appointment, no en el controlador HTTP.
 */
enum AppointmentStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';

    /**
     * Traduccion a espanol para la interfaz de usuario.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::CONFIRMED => 'Confirmada',
            self::CANCELLED => 'Cancelada',
        };
    }

    /**
     * Una cita activa ocupa agenda: es la que bloquea el horario del medico.
     */
    public function isActive(): bool
    {
        return $this !== self::CANCELLED;
    }

    public function isTerminal(): bool
    {
        return $this === self::CANCELLED;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::CONFIRMED, self::CANCELLED],
            self::CONFIRMED => [self::CANCELLED, self::PENDING],
            self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
