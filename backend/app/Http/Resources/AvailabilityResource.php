<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\ValueObjects\AvailabilityResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AvailabilityResult $resource
 */
final class AvailabilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
