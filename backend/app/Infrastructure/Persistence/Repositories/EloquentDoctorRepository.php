<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Contracts\DoctorRepositoryInterface;
use App\Domain\Entities\Doctor;
use App\Domain\Exceptions\DuplicateResource;
use App\Domain\Exceptions\ResourceNotFound;
use App\Domain\ValueObjects\LicenseNumber;
use App\Domain\ValueObjects\PagedResult;
use App\Infrastructure\Persistence\DoctorMapper;
use App\Infrastructure\Persistence\Models\DoctorModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Implementacion Eloquent de DoctorRepositoryInterface.
 */
final class EloquentDoctorRepository implements DoctorRepositoryInterface
{
    /** @var array<string, string> */
    public const SORTABLE = [
        'id' => 'id',
        'full_name' => 'full_name',
        'specialty' => 'specialty',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    public function save(Doctor $doctor): Doctor
    {
        try {
            $model = new DoctorModel();
            $model->fill(DoctorMapper::toAttributes($doctor));
            $model->created_at = $doctor->createdAt();
            $model->updated_at = $doctor->updatedAt();
            $model->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw $this->translateUniqueViolation($doctor, $e);
        }

        $doctor->assignId((int) $model->id);

        return $doctor;
    }

    public function update(Doctor $doctor): Doctor
    {
        $model = DoctorModel::query()->withTrashed()->find($doctor->id());

        if ($model === null) {
            throw ResourceNotFound::doctor((int) $doctor->id());
        }

        try {
            $model->fill(DoctorMapper::toAttributes($doctor));
            $model->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw $this->translateUniqueViolation($doctor, $e);
        }

        return $doctor;
    }

    public function findById(int $id): ?Doctor
    {
        $model = DoctorModel::query()->withTrashed()->find($id);

        return $model === null ? null : DoctorMapper::toEntity($model);
    }

    public function findByLicenseNumber(LicenseNumber $licenseNumber): ?Doctor
    {
        $model = DoctorModel::query()
            ->withTrashed()
            ->where('license_number', $licenseNumber->value())
            ->first();

        return $model === null ? null : DoctorMapper::toEntity($model);
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        return DoctorModel::query()
            ->where('email', $email)
            ->when($exceptId !== null, static fn (Builder $q): Builder => $q->whereKeyNot($exceptId))
            ->exists();
    }

    public function softDelete(Doctor $doctor): void
    {
        DoctorModel::query()
            ->whereKey($doctor->id())
            ->update(['deleted_at' => now()]);
    }

    public function paginate(int $page, int $perPage, array $filters = []): PagedResult
    {
        $query = DoctorModel::query();

        if (! ($filters['include_deleted'] ?? false)) {
            $query->whereNull('deleted_at');
        }

        if (($status = $filters['status'] ?? null) !== null && $status !== '') {
            $query->where('status', $status);
        }

        if (($specialty = trim((string) ($filters['specialty'] ?? ''))) !== '') {
            $query->where('specialty', 'ILIKE', '%'.$specialty.'%');
        }

        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(static function (Builder $inner) use ($like): void {
                $inner->where('full_name', 'ILIKE', $like)
                    ->orWhere('license_number', 'ILIKE', $like)
                    ->orWhere('specialty', 'ILIKE', $like);
            });
        }

        $sortColumn = self::SORTABLE[$filters['sort_by'] ?? ''] ?? 'full_name';
        $direction = ($filters['sort_direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $paginator = $query
            ->orderBy($sortColumn, $direction)
            ->orderBy('id', 'asc')
            ->paginate(perPage: $perPage, page: $page);

        return $this->toPagedResult($paginator);
    }

    /**
     * @return list<Doctor>
     */
    public function allActive(): array
    {
        return DoctorModel::query()
            ->active()
            ->orderBy('full_name')
            ->get()
            ->map(static fn (DoctorModel $m): Doctor => DoctorMapper::toEntity($m))
            ->all();
    }

    public function countAll(): int
    {
        return DoctorModel::query()->count();
    }

    /**
     * @param LengthAwarePaginator<int, DoctorModel> $paginator
     * @return PagedResult<Doctor>
     */
    private function toPagedResult(LengthAwarePaginator $paginator): PagedResult
    {
        $items = array_values(array_map(
            static fn (DoctorModel $model): Doctor => DoctorMapper::toEntity($model),
            $paginator->items(),
        ));

        /** @var PagedResult<Doctor> $result */
        $result = PagedResult::make(
            $items,
            $paginator->total(),
            $paginator->currentPage(),
            $paginator->perPage(),
        );

        return $result;
    }

    private function translateUniqueViolation(
        Doctor $doctor,
        \Illuminate\Database\UniqueConstraintViolationException $exception,
    ): DuplicateResource {
        $message = $exception->getMessage();

        if (str_contains($message, 'doctors_license_number_unique')) {
            return DuplicateResource::doctorLicense($doctor->licenseNumber()->value());
        }

        if (str_contains($message, 'doctors_email_unique')) {
            return DuplicateResource::doctorEmail($doctor->email()?->value() ?? '(vacio)');
        }

        return DuplicateResource::doctorLicense($doctor->licenseNumber()->value());
    }
}
