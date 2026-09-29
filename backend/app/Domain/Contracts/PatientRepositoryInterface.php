<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\Entities\Patient;
use App\Domain\ValueObjects\DocumentId;
use App\Domain\ValueObjects\PagedResult;
use Carbon\CarbonImmutable;

/**
 * Contrato de persistencia de pacientes (inversion de dependencias).
 *
 * La interfaz vive en el Dominio, la implementacion en Infraestructura. Por eso
 * la capa de aplicacion puede testearse con un doble en memoria, y la tecnologia
 * de almacenamiento puede cambiar sin tocar una sola linea de servicio.
 */
interface PatientRepositoryInterface
{
    public function save(Patient $patient): Patient;

    public function update(Patient $patient): Patient;

    public function findById(int $id): ?Patient;

    public function findByDocumentId(DocumentId $documentId): ?Patient;

    public function emailExists(string $email, ?int $exceptId = null): bool;

    /**
     * Borrado logico: marca deleted_at y conserva el registro para auditoria.
     */
    public function softDelete(Patient $patient): void;

    /**
     * @param array{
     *     search?: string|null,
     *     status?: string|null,
     *     sort_by?: string,
     *     sort_direction?: string,
     *     include_deleted?: bool
     * } $filters
     * @return PagedResult<Patient>
     */
    public function paginate(int $page, int $perPage, array $filters = []): PagedResult;

    /**
     * @return PagedResult<Patient>
     */
    public function searchForDoctorAvailability(int $doctorId, CarbonImmutable $from, CarbonImmutable $to): PagedResult;
}
