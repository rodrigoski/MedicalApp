<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\ValueObjects\TimeRange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read TimeRange $resource
 */
final class TimeRangeResource extends JsonResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
