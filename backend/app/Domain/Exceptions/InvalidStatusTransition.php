<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\Enums\AppointmentStatus;

/**
 * Transicion de estado no permitida en la maquina de estados de la Cita.
 *
 *   PENDIENTE  -> CONFIRMADA | CANCELADA
 *   CONFIRMADA -> CANCELADA | PENDIENTE
 *   CANCELADA  -> (terminal)
 */
final class InvalidStatusTransition extends DomainException
{
    private function __construct(
        string $message,
        private readonly AppointmentStatus $from,
        private readonly AppointmentStatus $to,
    ) {
        parent::__construct($message);
    }

    public static function notAllowed(
        AppointmentStatus $from,
        AppointmentStatus $to,
    ): self {
        $allowed = $from->allowedTransitions();

        $hint = $allowed === []
            ? sprintf(
                'El estado "%s" es terminal y no admite transiciones.',
                $from->label(),
            )
            : sprintf(
                'Desde "%s" solo se puede pasar a: %s.',
                $from->label(),
                implode(', ', array_map(
                    static fn (AppointmentStatus $s): string => $s->label(),
                    $allowed,
                )),
            );

        return new self(
            sprintf('Transicion invalida de "%s" a "%s". %s', $from->label(), $to->label(), $hint),
            $from,
            $to,
        );
    }

    public function errorCode(): string
    {
        return 'appointment.invalid_status_transition';
    }

    public function context(): array
    {
        return [
            'from' => $this->from->value,
            'to' => $this->to->value,
            'allowed' => array_map(
                static fn (AppointmentStatus $s): string => $s->value,
                $this->from->allowedTransitions(),
            ),
        ];
    }
}
