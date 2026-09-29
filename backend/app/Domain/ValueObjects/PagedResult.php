<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Pagina de resultados ya materializada en el dominio.
 *
 * Evita que la capa de aplicacion dependa de la clase de paginacion de Eloquent
 * (Illuminate\Pagination\LengthAwarePaginator). Si manana el repositorio usa
 * otro motor, el contrato de la capa de aplicacion no cambia.
 *
 * @template T
 */
final readonly class PagedResult
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    /**
     * @param list<T> $items
     */
    public static function make(array $items, int $total, int $page, int $perPage): self
    {
        return new self($items, $total, $page, $perPage);
    }

    /**
     * @return static
     */
    public static function empty(int $perPage = 15): self
    {
        /** @var static $instance */
        $instance = new self([], 0, 1, $perPage);

        return $instance;
    }

    public function lastPage(): int
    {
        if ($this->perPage <= 0) {
            return 1;
        }

        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasMorePages(): bool
    {
        return $this->page < $this->lastPage();
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Metadatos de paginacion serializables.
     *
     * @return array<string, int|bool>
     */
    public function meta(): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'last_page' => $this->lastPage(),
            'has_more' => $this->hasMorePages(),
        ];
    }
}
