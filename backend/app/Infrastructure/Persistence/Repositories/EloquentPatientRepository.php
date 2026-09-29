<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Contracts\PatientRepositoryInterface;
use App\Domain\Entities\Patient;
use App\Domain\Enums\ResourceStatus;
use App\Domain\Exceptions\DuplicateResource;
use App\Domain\ValueObjects\DocumentId;
use App\Domain\ValueObjects\PagedResult;
use App\Infrastructure\Persistence\Models\PatientModel;
use App\Infrastructure\Persistence\PatientMapper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Implementacion Eloquent/PostgreSQL de PatientRepositoryInterface.
 *
 * RESPONSABILIDADES (y solo estas):
 *   - Traducir entidades <-> filas con el mapper.
 *   - Ejecutar consultas.
 *   - Traducir errores del motor a excepciones de dominio.
 *
 * NO contiene reglas de negocio. No decide si un paciente puede duplicarse mas
 * alla de lanzar la excepcion cuando el indice unico revienta.
 *
 * SEGURIDAD: la columna de ordenamiento se valida contra SORTABLE. Sin esa
 * lista blanca, un `ORDER BY ?` con entrada del usuario seria inyeccion SQL.
 */
final class EloquentPatientRepository implements PatientRepositoryInterface
{
    /**
     * Columnas permitidas para ordenar. Lista blanca = defensa contra
     * inyeccion SQL a traves del parametro `sort_by`.
     */
    public const SORTABLE = [
        'id' => 'id',
        'full_name' => 'full_name',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'birth_date' => 'birth_date',
        'document_id' => 'document_id',
    ];

    public function save(Patient $patient): Patient
    {
        try {
            $model = new PatientModel();
            $model->fill(PatientMapper::toAttributes($patient));
            $model->created_at = $patient->createdAt();
            $model->updated_at = $patient->updatedAt();
            $model->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw $this->translateUniqueViolation($patient, $e);
        }

        $patient->assignId((int) $model->id);

        return $patient;
    }

    public function update(Patient $patient): Patient
    {
        $model = PatientModel::query()
            ->withTrashed()
            ->find($patient->id());

        if ($model === null) {
            throw \App\Domain\Exceptions\ResourceNotFound::patient((int) $patient->id());
        }

        try {
            $model->fill(PatientMapper::toAttributes($patient));
            $model->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw $this->translateUniqueViolation($patient, $e);
        }

        return $patient;
    }

    public function findById(int $id): ?Patient
    {
        $model = PatientModel::query()->withTrashed()->find($id);

        return $model === null ? null : PatientMapper::toEntity($model);
    }

    public function findByDocumentId(DocumentId $documentId): ?Patient
    {
        $model = PatientModel::query()
            ->withTrashed()
            ->where('document_id', $documentId->value())
            ->first();

        return $model === null ? null : PatientMapper::toEntity($model);
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        return PatientModel::query()
            ->where('email', $email)
            ->when($exceptId !== null, static fn (Builder $q): Builder => $q->whereKeyNot($exceptId))
            ->exists();
    }

    public function softDelete(Patient $patient): void
    {
        PatientModel::query()
            ->whereKey($patient->id())
            ->update(['deleted_at' => CarbonImmutable::now('UTC')]);
    }

    public function paginate(int $page, int $perPage, array $filters = []): PagedResult
    {
        $query = PatientModel::query();

        if (! ($filters['include_deleted'] ?? false)) {
            $query->whereNull('deleted_at');
        }

        if (($status = $filters['status'] ?? null) !== null && $status !== '') {
            $query->where('status', $status);
        }

        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $this->applySearch($query, $search);
        }

        $sortColumn = self::SORTABLE[$filters['sort_by'] ?? ''] ?? 'created_at';
        $direction = ($filters['sort_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $paginator = $query
            ->orderBy($sortColumn, $direction)
            ->orderBy('id', 'desc')       // desempate estable
            ->paginate(perPage: $perPage, page: $page);

        return $this->toPagedResult($paginator);
    }

    public function searchForDoctorAvailability(int $doctorId, CarbonImmutable $from, CarbonImmutable $to): PagedResult
    {
        // Metodo de conveniencia para el panel: devuelve los pacientes que ya
        // tienen cita con un medico en una ventana. Se mantiene en el
        // repositorio porque es una consulta de lectura, no una regla.
        $paginator = PatientModel::query()
            ->whereHas('appointments', static function (Builder $q) use ($doctorId, $from, $to): void {
                $q->where('doctor_id', $doctorId)
                    ->where('starts_at', '<', $to->toDateTimeString())
                    ->where('ends_at', '>', $from->toDateTimeString());
            })
            ->orderBy('full_name')
            ->paginate(50);

        return $this->toPagedResult($paginator);
    }

    /**
     * Busqueda insensible a mayusculas sobre nombre, documento y correo.
     *
     * NOTA: los parametros van vinculados por PDO, nunca concatenados. El
     * operador ILIKE es especifico de PostgreSQL y por eso vive aqui y no en
     * el dominio.
     *
     * @param Builder<PatientModel> $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        // Se escapan los comodines de SQL: si el usuario busca "100%", el "%" es
        // un comodin y devolveria resultados ajenos a la busqueda.
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        // ILIKE es especifico de PostgreSQL y no puede usar indice; en SQLite
        // (pruebas rapidas) se emula con LOWER(). La diferencia es de motor, no
        // de comportamiento observable, asi que se resuelve aqui y no en el
        // dominio.
        $isPostgres = $query->getConnection()->getDriverName() === 'pgsql';

        $query->where(static function (Builder $inner) use ($like, $isPostgres): void {
            foreach (['full_name', 'document_id', 'email'] as $column) {
                if ($isPostgres) {
                    $inner->orWhere($column, 'ILIKE', $like);

                    continue;
                }

                $inner->orWhereRaw("LOWER({$column}) LIKE LOWER(?)", [$like]);
            }
        });
    }

    /**
     * @param LengthAwarePaginator<int, PatientModel> $paginator
     * @return PagedResult<Patient>
     */
    private function toPagedResult(LengthAwarePaginator $paginator): PagedResult
    {
        $items = array_values(array_map(
            static fn (PatientModel $model): Patient => PatientMapper::toEntity($model),
            $paginator->items(),
        ));

        /** @var PagedResult<Patient> $result */
        $result = PagedResult::make(
            $items,
            $paginator->total(),
            $paginator->currentPage(),
            $paginator->perPage(),
        );

        return $result;
    }

    private function translateUniqueViolation(
        Patient $patient,
        \Illuminate\Database\UniqueConstraintViolationException $exception,
    ): DuplicateResource {
        $message = $exception->getMessage();

        if (str_contains($message, 'patients_document_id_unique')) {
            return DuplicateResource::patientDocument($patient->documentId()->value());
        }

        if (str_contains($message, 'patients_email_unique')) {
            return DuplicateResource::patientEmail($patient->email()?->value() ?? '(vacio)');
        }

        return DuplicateResource::patientDocument($patient->documentId()->value());
    }
}
