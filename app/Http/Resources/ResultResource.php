<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Result;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Result
 */
class ResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'position' => $this->position,
            'points' => (float) $this->points,
            'dnf' => $this->dnf,
            'fastest_lap' => $this->fastest_lap,
            'grid_position' => $this->grid_position,
            'driver' => new DriverResource($this->whenLoaded('driver')),
        ];
    }
}
