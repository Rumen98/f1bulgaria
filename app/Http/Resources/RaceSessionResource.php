<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RaceSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RaceSession
 */
class RaceSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'scheduled_at_sofia' => $this->scheduled_at_utc
                ?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y H:i'),
        ];
    }
}
