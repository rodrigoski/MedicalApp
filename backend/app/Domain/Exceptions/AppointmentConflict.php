<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\TimeRange;

/**
 * Conflicto de agenda: no se puede agendar la cita solicitada.
 *
 * Es la excepcion que materializa la REGLA DE NEGOCIO CENTRAL del proyecto:
 * "un medico no puede tener dos citas traslapadas en el tiempo".
 *
 * Se distingue el origen del conflicto (motivo) porque el diagnostico importa:
 *   - doctor_busy    : la regla de traslape (lo mas frecuente).
 *   - patient_busy   : el paciente ya tiene otra cita a esa hora.
 *   - past_date      : no se agenda en el pasado.
 *   - outside_hours  : fuera de la jornada laboral del medico.
 *   - patient_inactive / doctor_inactive : el recurso no opera.
 *   - daily_limit    : se alcanzo el maximo de citas por dia del medico.
 *   - db_constraint  : la restriction EXCLUDE de PostgreSQL veto la operacion
 *                      (carrera concurrente). Verificacion de integridad a nivel
 *                      de motor: aunque la aplicacion falle, la BD no permite el
 *                      traslape.
 */
final class AppointmentConflict extends DomainException
{
    public const DOCTOR_BUSY = 'doctor_busy';
    public const PATIENT_BUSY = 'patient_busy';
    public const PAST_DATE = 'past_date';
    public const OUTSIDE_HOURS = 'outside_hours';
    public const PATIENT_INACTIVE = 'patient_inactive';
    public const DOCTOR_INACTIVE = 'doctor_inactive';
    public const DAILY_LIMIT = 'daily_limit';
    public const DB_CONSTRAINT = 'db_constraint';

    /**
     * @param array<string, scalar|null> $context
     */
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly TimeRange $requestedRange,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function doctorBusy(
        int $doctorId,
        string $doctorName,
        TimeRange $requested,
        TimeRange $conflicting,
    ): self {
        return new self(
            sprintf(
                'El medico "%s" ya tiene una cita de %s a %s (%s - %s) que se traslapa con el horario solicitado.',
                $doctorName,
                $conflicting->startAt()->setTimezone('UTC')->format('H:i'),
                $conflicting->endAt()->setTimezone('UTC')->format('H:i'),
                $conflicting->startAt()->toDateString(),
                $conflicting->endAt()->toDateString(),
            ),
            self::DOCTOR_BUSY,
            $requested,
            [
                'doctor_id' => $doctorId,
                'conflicting_starts_at' => $conflicting->startAt()->toIso8601String(),
                'conflicting_ends_at' => $conflicting->endAt()->toIso8601String(),
            ],
        );
    }

    public static function patientBusy(
        int $patientId,
        TimeRange $requested,
        TimeRange $conflicting,
    ): self {
        return new self(
            sprintf(
                'El paciente ya tiene una cita de %s a %s que se traslapa con el horario solicitado.',
                $conflicting->startAt()->setTimezone('UTC')->format('H:i'),
                $conflicting->endAt()->setTimezone('UTC')->format('H:i'),
            ),
            self::PATIENT_BUSY,
            $requested,
            [
                'patient_id' => $patientId,
                'conflicting_starts_at' => $conflicting->startAt()->toIso8601String(),
                'conflicting_ends_at' => $conflicting->endAt()->toIso8601String(),
            ],
        );
    }

    public static function inThePast(TimeRange $requested): self
    {
        return new self(
            'No se pueden agendar citas en el pasado.',
            self::PAST_DATE,
            $requested,
        );
    }

    public static function outsideWorkingHours(
        int $doctorId,
        string $doctorName,
        TimeRange $requested,
        string $window,
    ): self {
        return new self(
            sprintf(
                'El horario solicitado (%s - %s) queda fuera de la jornada del medico "%s" (%s).',
                $requested->startAt()->setTimezone('UTC')->format('H:i'),
                $requested->endAt()->setTimezone('UTC')->format('H:i'),
                $doctorName,
                $window,
            ),
            self::OUTSIDE_HOURS,
            $requested,
            ['doctor_id' => $doctorId, 'working_hours' => $window],
        );
    }

    public static function patientInactive(int $patientId, string $patientName): self
    {
        return new self(
            sprintf('El paciente "%s" esta inactivo y no admite nuevas citas.', $patientName),
            self::PATIENT_INACTIVE,
            TimeRange::from(
                \Carbon\CarbonImmutable::now('UTC'),
                \Carbon\CarbonImmutable::now('UTC')->addHour(),
            ),
            ['patient_id' => $patientId],
        );
    }

    public static function doctorInactive(int $doctorId, string $doctorName): self
    {
        return new self(
            sprintf('El medico "%s" esta inactivo y no admite nuevas citas.', $doctorName),
            self::DOCTOR_INACTIVE,
            TimeRange::from(
                \Carbon\CarbonImmutable::now('UTC'),
                \Carbon\CarbonImmutable::now('UTC')->addHour(),
            ),
            ['doctor_id' => $doctorId],
        );
    }

    public static function dailyLimitReached(int $doctorId, int $limit, string $day): self
    {
        $now = \Carbon\CarbonImmutable::now('UTC');

        return new self(
            sprintf(
                'El medico alcanzo el maximo de %d citas para el %s. Libere agenda o use otro dia.',
                $limit,
                $day,
            ),
            self::DAILY_LIMIT,
            TimeRange::from($now, $now->addHour()),
            ['doctor_id' => $doctorId, 'max_per_day' => $limit],
        );
    }

    public static function databaseConstraintViolated(): self
    {
        $now = \Carbon\CarbonImmutable::now('UTC');

        return new self(
            'La operacion fue rechazada por la restriccion de integridad de la agenda: '
            .'el horario se occupo en una transaccion concurrente. Intente con otro horario.',
            self::DB_CONSTRAINT,
            TimeRange::from($now, $now->addHour()),
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function requestedRange(): TimeRange
    {
        return $this->requestedRange;
    }

    public function errorCode(): string
    {
        return 'appointment.conflict.'.$this->reason;
    }

    public function context(): array
    {
        return [
            'reason' => $this->reason,
            'requested_starts_at' => $this->requestedRange->startAt()->toIso8601String(),
            'requested_ends_at' => $this->requestedRange->endAt()->toIso8601String(),
        ] + $this->context;
    }
}
