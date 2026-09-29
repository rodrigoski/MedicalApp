<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\Entities\Doctor;
use App\Domain\ValueObjects\LicenseNumber;
use App\Domain\ValueObjects\PagedResult;

interface DoctorRepositoryInterface
{
    public function save(Doctor $doctor): Doctor;

    public function update(Doctor $doctor): Doctor;

    public function findById(int $id): ?Doctor;

    public function findByLicenseNumber(LicenseNumber $licenseNumber): ?Doctor;

    public function emailExists(string $email, ?int $exceptId = null): bool;

    public function softDelete(Doctor $doctor): void;

    /**
     * @param array{
     *     search?: string|null,
     *     status?: string|null,
     *     specialty?: string|null,
     *     sort_by?: string,
     *     sort_direction?: string,
     *     include_deleted?: bool
     * } $filters
     * @return PagedResult<Doctor>
     */
    public function paginate(int $page, int $perPage, array $filters = []): PagedResult;

    /**
     * @return list<Doctor>
     */
    public function allActive(): array;

    public function countAll(): int;
}
