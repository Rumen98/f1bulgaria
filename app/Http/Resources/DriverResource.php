<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Driver;
use App\Support\DriverName;
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
            // `full_name` е името за екрана (кирилица, ако е известна). Латиницата
            // остава достъпна през `first_name`/`last_name`.
            'full_name' => DriverName::display($this->slug, $this->fullName()),
            'slug' => $this->slug,
            'code' => $this->driver_code,
            'number' => $this->permanent_number,
            'country_code' => $this->country_code,
            'constructor' => new ConstructorResource($this->whenLoaded('constructor')),
        ];
    }
}
