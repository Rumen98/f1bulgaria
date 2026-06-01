<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Driver
 */
class DriverResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'slug' => $this->slug,
            'code' => $this->driver_code,
            'number' => $this->permanent_number,
            'country_code' => $this->country_code,
            'constructor' => new ConstructorResource($this->whenLoaded('constructor')),
        ];
    }
}
