<?php

declare(strict_types=1);

namespace App\Application\DTOs;

/**
 * Parametros de listado, ya normalizados y acotados.
 *
 * CRITERIO DE SEGURIDAD: `sortBy` se valida contra una lista blanca en el
 * repositorio. Sin esa validacion, un ORDER BY interpolado desde la entrada del
 * usuario es una inyeccion SQL. Ver EloquentPatientRepository::SORTABLE.
 */
final readonly class ListQuery
{
    public const MAX_PER_PAGE = 100;
    public const DEFAULT_PER_PAGE = 15;

    /**
     * @param array<string, scalar|null> $filters
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?string $search = null,
        public ?string $sortBy = null,
        public string $sortDirection = 'desc',
        public array $filters = [],
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input, int $defaultPerPage = self::DEFAULT_PER_PAGE): self
    {
        $perPage = (int) ($input['per_page'] ?? $defaultPerPage);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $direction = strtolower((string) ($input['sort_direction'] ?? 'desc'));
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $filters = [];
        foreach (['status', 'specialty', 'doctor_id', 'patient_id', 'date', 'is_active'] as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $filters[$key] = $input[$key];
            }
        }

        return new self(
            page: max(1, (int) ($input['page'] ?? 1)),
            perPage: $perPage,
            search: isset($input['search']) && trim((string) $input['search']) !== ''
                ? trim((string) $input['search'])
                : null,
            sortBy: isset($input['sort_by']) && trim((string) $input['sort_by']) !== ''
                ? trim((string) $input['sort_by'])
                : null,
            sortDirection: $direction,
            filters: $filters,
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
