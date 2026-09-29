<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\DTOs\ListQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Normaliza y acota los parametros de listado.
 *
 * SEGURIDAD: acota `per_page` a un maximo de 100. Sin ese tope, un
 * `?per_page=1000000` permitiria agotar la memoria del servidor (OWASP API4).
 * El limite esta en ListQuery::MAX_PER_PAGE y se aplica aqui, no en cada
 * controlador.
 */
final class ListQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListQuery::MAX_PER_PAGE],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort_by' => ['sometimes', 'nullable', 'string', 'max:40'],
            'sort_direction' => ['sometimes', 'nullable', 'in:asc,desc'],
            'status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'specialty' => ['sometimes', 'nullable', 'string', 'max:80'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
        ];
    }

    public function toQuery(): ListQuery
    {
        return ListQuery::fromArray($this->validated());
    }
}
