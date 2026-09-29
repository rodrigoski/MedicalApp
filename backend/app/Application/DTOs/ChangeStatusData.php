<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\Enums\AppointmentStatus;

/**
 * DTO para cambiar el estado de una cita.
 *
 * El motivo es obligatorio cuando se cancela: sin diagnostico, la historial de
 * la agenda no sirve para auditar por que se libero el horario.
 */
final readonly class ChangeStatusData
{
    public function __construct(
        public AppointmentStatus $target,
        public ?string $reason = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $target = AppointmentStatus::from((string) ($input['status'] ?? ''));

        return new self(
            target: $target,
            reason: isset($input['reason']) && trim((string) $input['reason']) !== ''
                ? trim((string) $input['reason'])
                : null,
        );
    }
}
