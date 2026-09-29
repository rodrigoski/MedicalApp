<?php

declare(strict_types=1);

namespace App\Domain\Policies;

use App\Domain\Entities\Doctor;
use App\Domain\Entities\Patient;
use App\Domain\Exceptions\AppointmentConflict;
use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * REGLA DE NEGOCIO CENTRAL: un medico no puede tener dos citas que se
 * traslapen en el tiempo.
 *
 * Esta clase es la UNICA que decide si un horario es agendable. Vive en el
 * Dominio porque:
 *   1. No depende de HTTP, base de datos ni de ningun framework (testeable sin
 *      levantar nada).
 *   2. Es la respuesta a la pregunta "donde viven las validaciones de negocio":
 *      ni en el controlador (que solo traduce HTTP) ni en el repositorio (que
 *      solo persiste), sino en el Dominio, invocado por la capa de Aplicacion.
 *
 * Recibe los datos ya consultados (Doctor, Patient, TimeRange) y lanza
 * AppointmentConflict. La consulta de "que citas existen" la hace el
 * repositorio desde la capa de Aplicacion: aqui no hay acceso a Eloquent.
 *
 * Decision adicional documentada: una cita CANCELADA libera el horario, por
 * eso se filtran antes de comparar (Appointment::occupiesSchedule()).
 */
final class AppointmentOverlapPolicy
{
    /**
     * @param int    $maxAppointmentsPerDoctorPerDay  Techo operativo (no es una regla invariante: se configura por clinica).
     * @param int    $minutesOfToleranceForPastDates Margen de reloj; 0 = estricto.
     * @param string $clinicTimezone                  Zona en la que se vive la jornada del medico.
     */
    public function __construct(
        private readonly int $maxAppointmentsPerDoctorPerDay = 24,
        private readonly int $minutesOfToleranceForPastDates = 0,
        private readonly string $clinicTimezone = 'UTC',
    ) {
    }

    /**
     * Valida que la cita pueda crearse/reprogramarse.
     *
     * @param list<TimeRange> $doctorBusyRanges Intervalos ocupados por el medico.
     * @param list<TimeRange> $patientBusyRanges Intervalos ocupados por el paciente.
     * @param int $existingAppointmentsOnDay Citas ya creadas del medico para ese dia.
     *
     * @throws AppointmentConflict
     */
    public function assertCanBook(
        TimeRange $requested,
        Doctor $doctor,
        Patient $patient,
        array $doctorBusyRanges,
        array $patientBusyRanges,
        int $existingAppointmentsOnDay,
        CarbonImmutable $now,
    ): void {
        // 1. No se agenda en el pasado.
        $earliestAllowed = $now->subMinutes($this->minutesOfToleranceForPastDates);

        if ($requested->startAt()->lessThan($earliestAllowed)) {
            throw AppointmentConflict::inThePast($requested);
        }

        // 2. El paciente debe estar activo.
        if (! $patient->isActive()) {
            throw AppointmentConflict::patientInactive(
                (int) $patient->id(),
                $patient->fullName(),
            );
        }

        // 3. El medico debe estar activo.
        if (! $doctor->isActive()) {
            throw AppointmentConflict::doctorInactive(
                (int) $doctor->id(),
                $doctor->fullName(),
            );
        }

        // 3.b La cita debe caer dentro de la jornada del medico y en un dia de
        //     atencion. Sin esta comprobacion, la API permitiria agendar a las
        //     03:00 o un domingo, y el conflicto de traslape nunca se detectaria
        //     porque no existiria otra cita a esa hora.
        $this->assertWithinWorkingHours($requested, $doctor);

        // 4. REGLA PRINCIPAL: traslape del medico.
        foreach ($doctorBusyRanges as $busy) {
            if ($requested->overlaps($busy)) {
                throw AppointmentConflict::doctorBusy(
                    (int) $doctor->id(),
                    $doctor->fullName(),
                    $requested,
                    $busy,
                );
            }
        }

        // 5. Coherencia de agenda: el paciente tampoco puede estar en dos sitios.
        foreach ($patientBusyRanges as $busy) {
            if ($requested->overlaps($busy)) {
                throw AppointmentConflict::patientBusy(
                    (int) $patient->id(),
                    $requested,
                    $busy,
                );
            }
        }

        // 6. Techo de carga por dia para el medico.
        if ($existingAppointmentsOnDay >= $this->maxAppointmentsPerDoctorPerDay) {
            throw AppointmentConflict::dailyLimitReached(
                (int) $doctor->id(),
                $this->maxAppointmentsPerDoctorPerDay,
                $requested->startAt()->toDateString(),
            );
        }
    }

    public function maxAppointmentsPerDoctorPerDay(): int
    {
        return $this->maxAppointmentsPerDoctorPerDay;
    }

    public function clinicTimezone(): string
    {
        return $this->clinicTimezone;
    }

    /**
     * Verifica jornada y dia de atencion del medico.
     *
     * La comparacion se hace en la zona local de la clinica porque la jornada
     * ("de 8 a 18") es un fenomeno del reloj de pared del consultorio, no del
     * UTC. Un medico con jornada 8-18 en Bogota (UTC-5) atiende de 13:00 a
     * 18:00 UTC; validar en UTC le permitiria (o le prohibiria) horas
     * equivocadas.
     *
     * @throws AppointmentConflict
     */
    private function assertWithinWorkingHours(TimeRange $requested, Doctor $doctor): void
    {
        $timezone = new DateTimeZone($this->clinicTimezone);

        $localStart = $requested->startAt()->setTimezone($timezone);
        $localEnd = $requested->endAt()->setTimezone($timezone);

        $window = sprintf(
            '%02d:00 - %02d:00 (%s)',
            $doctor->workingDayStartHour(),
            $doctor->workingDayEndHour(),
            $this->clinicTimezone,
        );

        if (! $doctor->worksOn($localStart)) {
            throw AppointmentConflict::outsideWorkingHours(
                (int) $doctor->id(),
                $doctor->fullName(),
                $requested,
                $window.' - el medico no atiende ese dia',
            );
        }

        // Una cita que cruza la medianoche local nunca cabe en una jornada.
        $startMinutes = ((int) $localStart->format('H')) * 60 + (int) $localStart->format('i');
        $endMinutes = ((int) $localEnd->format('H')) * 60 + (int) $localEnd->format('i');

        $windowStart = $doctor->workingDayStartHour() * 60;
        $windowEnd = $doctor->workingDayEndHour() * 60;

        if ($startMinutes < $windowStart || $endMinutes > $windowEnd || $endMinutes <= $startMinutes) {
            throw AppointmentConflict::outsideWorkingHours(
                (int) $doctor->id(),
                $doctor->fullName(),
                $requested,
                $window,
            );
        }
    }
}
