<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\Entities\Appointment;
use App\Domain\Enums\AppointmentStatus;
use App\Domain\ValueObjects\PagedResult;
use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;

interface AppointmentRepositoryInterface
{
    public function save(Appointment $appointment): Appointment;

    public function update(Appointment $appointment): Appointment;

    public function findById(int $id): ?Appointment;

    /**
     * Intervalos ocupados (no cancelados) del medico en una ventana.
     *
     * @return list<TimeRange>
     */
    public function busyRangesForDoctor(
        int $doctorId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $excludeAppointmentId = null,
    ): array;

    /**
     * @return list<TimeRange>
     */
    public function busyRangesForPatient(
        int $patientId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $excludeAppointmentId = null,
    ): array;

    /**
     * Numero de citas activas (no canceladas) de un medico en una ventana.
     *
     * Recibe una ventana EXPLICITA [from, to) en UTC y no "un dia": quien sabe
     * donde empieza el dia de la clinica es la politica, no el repositorio. Si
     * el repositorio hiciera `startOfDay()` por su cuenta, contaria por dia UTC
     * y el techo diario se podria eludir reservando de madrugada.
     */
    public function countActiveForDoctorBetween(
        int $doctorId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): int;

    /**
     * @param array{
     *     doctor_id?: int|null,
     *     patient_id?: int|null,
     *     status?: string|null,
     *     from?: string|null,
     *     to?: string|null,
     *     sort_by?: string,
     *     sort_direction?: string
     * } $filters
     * @return PagedResult<Appointment>
     */
    public function paginate(int $page, int $perPage, array $filters = []): PagedResult;

    /**
     * Resumen para el panel de indicadores.
     *
     * @return array<string, int>
     */
    public function statistics(): array;

    public function countByStatus(): array;

    public function countUpcoming(CarbonImmutable $from): int;
}
