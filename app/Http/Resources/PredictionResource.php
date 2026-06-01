<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Prediction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Prediction
 */
class PredictionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'race_id' => $this->race_id,
            'p1_driver_id' => $this->p1_driver_id,
            'p2_driver_id' => $this->p2_driver_id,
            'p3_driver_id' => $this->p3_driver_id,
            'pole_driver_id' => $this->pole_driver_id,
            'fastest_lap_driver_id' => $this->fastest_lap_driver_id,
            'dnf_count' => $this->dnf_count,
            'safety_car' => $this->safety_car,
            'locked' => $this->isLocked(),
            'points' => $this->whenLoaded('score', fn () => $this->score?->points),
            'breakdown' => $this->whenLoaded('score', fn () => $this->score?->breakdown_json),
            'race' => $this->whenLoaded('race', fn () => [
                'id' => $this->race->id,
                'round' => $this->race->round,
                'name' => $this->race->name,
            ]),
        ];
    }
}
