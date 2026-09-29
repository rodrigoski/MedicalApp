<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Contracts\AppointmentRepositoryInterface;
use App\Domain\Entities\Appointment;
use App\Domain\Enums\AppointmentStatus;
use App\Domain\Exceptions\AppointmentConflict;
use App\Domain\Exceptions\ResourceNotFound;
use App\Domain\ValueObjects\PagedResult;
use App\Domain\ValueObjects\TimeRange;
use App\Infrastructure\Persistence\AppointmentMapper;
use App\Infrastructure\Persistence\Models\AppointmentModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Implementacion Eloquent de AppointmentRepositoryInterface.
 *
 * PUNTO CLAVE DE LA ARQUITECTURA: las consultas de traslape usan el indice
 * compuesto (doctor_id, starts_at) y el predicado semiabierto
 *
 *     starts_at < :to  AND  ends_at > :from
 *
 * que es EXACTAMENTE la misma definicion de traslape que TimeRange::overlaps().
 * Esa equivalencia entre la regla del dominio y la consulta de la base de datos
 * es lo que permite que la validacion en PHP y la restriccion EXCLUDE de
 * PostgreSQL coincidan sin divergir.
 */
final class EloquentAppointmentRepository implements AppointmentRepositoryInterface
{
    /** @var array<string, string> */
    public const SORTABLE = [
        'id' => 'id',
        'starts_at' => 'starts_at',
        'ends_at' => 'ends_at',
        'created_at' => 'created_at',
        'status' => 'status',
    ];

    public function save(Appointment $appointment): Appointment
    {
        try {
            $model = new AppointmentModel();
            $model->fill(AppointmentMapper::toAttributes($appointment));
            $model->created_at = $appointment->createdAt();
            $model->updated_at = $appointment->updatedAt();
            $model->save();
        } catch (QueryException $e) {
            throw $this->translateConstraintViolation($e);
        }

        $appointment->assignId((int) $model->id);

        return $appointment;
    }

    public function update(Appointment $appointment): Appointment
    {
        $model = AppointmentModel::query()->find($appointment->id());

        if ($model === null) {
            throw ResourceNotFound::appointment((int) $appointment->id());
        }

        try {
            $model->fill(AppointmentMapper::toAttributes($appointment));
            $model->save();
        } catch (QueryException $e) {
            throw $this->translateConstraintViolation($e);
        }

        return $appointment;
    }

    public function findById(int $id): ?Appointment
    {
        $model = AppointmentModel::query()->find($id);

        return $model === null ? null : AppointmentMapper::toEntity($model);
    }

    public function busyRangesForDoctor(
        int $doctorId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $excludeAppointmentId = null,
    ): array {
        $query = AppointmentModel::query()
            ->occupying()
            ->where('doctor_id', $doctorId)
            ->where('starts_at', '<', $to->toDateTimeString())
            ->where('ends_at', '>', $from->toDateTimeString());

        if ($excludeAppointmentId !== null) {
            $query->whereKeyNot($excludeAppointmentId);
        }

        return $this->toTimeRanges($query->get(['starts_at', 'ends_at']));
    }

    public function busyRangesForPatient(
        int $patientId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $excludeAppointmentId = null,
    ): array {
        $query = AppointmentModel::query()
            ->occupying()
            ->where('patient_id', $patientId)
            ->where('starts_at', '<', $to->toDateTimeString())
            ->where('ends_at', '>', $from->toDateTimeString());

        if ($excludeAppointmentId !== null) {
            $query->whereKeyNot($excludeAppointmentId);
        }

        return $this->toTimeRanges($query->get(['starts_at', 'ends_at']));
    }

    public function countActiveForDoctorBetween(
        int $doctorId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): int {
        // Ventana semiabierta [from, to) en UTC: el mismo predicado que usa
        // busyRangesForDoctor(), para que el conteo y el traslape no puedan
        // discrepar en los bordes del dia.
        return AppointmentModel::query()
            ->occupying()
            ->where('doctor_id', $doctorId)
            ->where('starts_at', '>=', $from->toDateTimeString())
            ->where('starts_at', '<', $to->toDateTimeString())
            ->count();
    }

    public function paginate(int $page, int $perPage, array $filters = []): PagedResult
    {
        $query = AppointmentModel::query()->with(['patient:id,full_name,document_id', 'doctor:id,full_name,specialty']);

        if (($doctorId = $filters['doctor_id'] ?? null) !== null && $doctorId !== '') {
            $query->where('doctor_id', (int) $doctorId);
        }

        if (($patientId = $filters['patient_id'] ?? null) !== null && $patientId !== '') {
            $query->where('patient_id', (int) $patientId);
        }

        if (($status = $filters['status'] ?? null) !== null && $status !== '') {
            $query->where('status', $status);
        }

        if (($date = $filters['date'] ?? null) !== null && $date !== '') {
            $day = CarbonImmutable::parse((string) $date, 'UTC')->startOfDay();
            $query->whereBetween('starts_at', [$day->toDateTimeString(), $day->endOfDay()->toDateTimeString()]);
        }

        if (($from = $filters['from'] ?? null) !== null && $from !== '') {
            $query->where('starts_at', '>=', CarbonImmutable::parse((string) $from)->toDateTimeString());
        }

        if (($to = $filters['to'] ?? null) !== null && $to !== '') {
            $query->where('starts_at', '<=', CarbonImmutable::parse((string) $to)->toDateTimeString());
        }

        $sortColumn = self::SORTABLE[$filters['sort_by'] ?? ''] ?? 'starts_at';
        $direction = ($filters['sort_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $paginator = $query
            ->orderBy($sortColumn, $direction)
            ->orderBy('id', 'desc')
            ->paginate(perPage: $perPage, page: $page);

        $items = array_values(array_map(
            static fn (AppointmentModel $model): Appointment => AppointmentMapper::toEntity($model),
            $paginator->items(),
        ));

        /** @var PagedResult<Appointment> $result */
        $result = PagedResult::make(
            $items,
            $paginator->total(),
            $paginator->currentPage(),
            $paginator->perPage(),
        );

        return $result;
    }

    public function statistics(): array
    {
        $total = AppointmentModel::query()->count();
        $active = AppointmentModel::query()->occupying()->count();
        $cancelled = AppointmentModel::query()
            ->where('status', AppointmentStatus::CANCELLED->value)
            ->count();
        $upcoming = $this->countUpcoming(CarbonImmutable::now('UTC'));

        return [
            'total' => $total,
            'active' => $active,
            'cancelled' => $cancelled,
            'upcoming' => $upcoming,
            'occupancy_rate' => $total > 0 ? round(($active / $total) * 100, 2) : 0.0,
        ];
    }

    public function countByStatus(): array
    {
        $rows = AppointmentModel::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $result = [];
        foreach (AppointmentStatus::cases() as $status) {
            $result[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $result;
    }

    public function countUpcoming(CarbonImmutable $from): int
    {
        return AppointmentModel::query()
            ->occupying()
            ->where('starts_at', '>=', $from->toDateTimeString())
            ->count();
    }

    /**
     * @param \Illuminate\Support\Collection<int, AppointmentModel> $models
     * @return list<TimeRange>
     */
    private function toTimeRanges($models): array
    {
        $ranges = [];

        foreach ($models as $model) {
            $ranges[] = TimeRange::from(
                CarbonImmutable::instance($model->starts_at),
                CarbonImmutable::instance($model->ends_at),
            );
        }

        return $ranges;
    }

    /**
     * Traduce una violacion de la restriccion EXCLUDE de PostgreSQL a una
     * excepcion de dominio legible.
     *
     * Esto es la RED DE SEGURIDAD de la regla de no traslape: si dos peticiones
     * simultaneas pasan la validacion de PHP a la vez, el motor de base de datos
     * rechaza una de ellas. El usuario recibe 409 en lugar de un error 500.
     */
    private function translateConstraintViolation(QueryException $e): \Throwable
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        // 23P01 = exclusion_violation (restriccion EXCLUDE de PostgreSQL).
        // Se reconocen AMBAS restricciones de no traslape (medico y paciente)
        // porque las dos son la misma regla aplicada a cada lado de la cita.
        if ($sqlState === '23P01'
            || str_contains($e->getMessage(), 'appointments_no_doctor_overlap')
            || str_contains($e->getMessage(), 'appointments_no_patient_overlap')
        ) {
            return AppointmentConflict::databaseConstraintViolated();
        }

        return $e;
    }
}
