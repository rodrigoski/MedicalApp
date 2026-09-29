<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Tipos de evento de dominio emitidos por el sistema.
 *
 * Son el contrato publico hacia el microservicio de notificaciones: si se anade
 * un valor aqui, el microservicio debe entenderlo (ver EventType en FastAPI).
 */
enum DomainEventType: string
{
    case PATIENT_REGISTERED = 'patient.registered';
    case PATIENT_UPDATED = 'patient.updated';
    case PATIENT_DELETED = 'patient.deleted';
    case DOCTOR_REGISTERED = 'doctor.registered';
    case DOCTOR_UPDATED = 'doctor.updated';
    case APPOINTMENT_BOOKED = 'appointment.booked';
    case APPOINTMENT_CONFIRMED = 'appointment.confirmed';
    case APPOINTMENT_CANCELLED = 'appointment.cancelled';
    case APPOINTMENT_RESCHEDULED = 'appointment.rescheduled';

    /**
     * Canales de notificacion que el microservicio debe activar.
     *
     * @return list<string>
     */
    public function notificationChannels(): array
    {
        return match ($this) {
            self::APPOINTMENT_BOOKED, self::APPOINTMENT_CONFIRMED => ['in_app', 'email'],
            self::APPOINTMENT_CANCELLED, self::APPOINTMENT_RESCHEDULED => ['in_app', 'email', 'sms'],
            self::PATIENT_REGISTERED => ['in_app', 'email'],
            default => ['in_app'],
        };
    }

    /**
     * Cobertura para la dimension "Pruebas" de la auditoria: todo evento
     * emitido debe tener al menos un canal y existir en el catalogo.
     *
     * @return array<string, list<string>>
     */
    public static function channelMap(): array
    {
        $map = [];
        foreach (self::cases() as $case) {
            $map[$case->value] = $case->notificationChannels();
        }

        return $map;
    }
}
