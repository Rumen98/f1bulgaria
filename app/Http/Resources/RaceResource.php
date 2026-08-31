<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Race;
use App\Services\Races\RaceNameLocalizer;
use App\Support\DriverName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Race
 */
class RaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'round' => $this->round,
            'name' => $this->name,
            'name_bg' => app(RaceNameLocalizer::class)->localize($this->jolpica_id, $this->name),
            'circuit' => $this->circuit,
            'country' => $this->country,
            'has_sprint' => $this->has_sprint,
            // UTC ISO за машинна обработка + форматирано софийско време за UI.
            'race_at_utc' => $this->race_datetime_utc?->toIso8601String(),
            'race_at_sofia' => $this->sofia($this->race_datetime_utc),
            'qualifying_at_utc' => $this->qualifying_datetime_utc?->toIso8601String(),
            'qualifying_at_sofia' => $this->sofia($this->qualifying_datetime_utc),
            'sprint_at_sofia' => $this->sofia($this->sprint_datetime_utc),
            // ПРОВЕДЕНО (по часовник) и ИМА РЕЗУЛТАТИ са различни неща и не
            // бива да се бъркат: източникът на резултати закъснява с часове,
            // а понякога с ден. Докато календарът делеше по `finished`,
            // изкараното състезание оставаше „предстоящо" и получаваше брояч
            // към час в миналото.
            'held' => $this->race_datetime_utc?->isPast() ?? false,
            'finished' => $this->whenLoaded('results', fn () => $this->results->isNotEmpty()),
            // Победителят, готов за показване — календарът иначе показваше
            // само дата и име на кръга за вече минали състезания.
            'winner' => $this->whenLoaded('results', function () {
                $first = $this->results->firstWhere('position', 1);

                return $first?->driver === null ? null : [
                    'name' => DriverName::display($first->driver->slug, $first->driver->fullName()),
                    'slug' => $first->driver->slug,
                    'team' => $first->driver->constructor?->name,
                    'color' => $first->driver->constructor?->color_hex,
                ];
            }),
            'sessions' => RaceSessionResource::collection($this->whenLoaded('sessions')),
            'results' => ResultResource::collection($this->whenLoaded('results')),
            'pole_driver' => new DriverResource($this->whenLoaded('poleDriver')),
            'had_safety_car' => $this->had_safety_car,
        ];
    }

    private function sofia(?Carbon $dt): ?string
    {
        return $dt?->copy()->setTimezone('Europe/Sofia')->format('d.m.Y H:i');
    }
}
