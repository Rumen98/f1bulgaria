<?php

declare(strict_types=1);

namespace App\Services\RaceData;

use App\Models\Race;
use App\Models\RaceDataRecap;
use Throwable;

/**
 * Сглобява рекапа: ключове → данни → числа → серии → текст → ред в базата.
 *
 * Провалът е тих само за потребителя, не за нас: причината влиза в
 * `last_error`, броячът се вдига, а дневният отчет ги чете. Мъртъв конвейер,
 * който никой не забелязва месеци наред, вече се е случвал на този проект.
 */
class RaceRecapGenerator
{
    public function __construct(
        private readonly RaceSessionResolver $resolver,
        private readonly RaceDataFetcher $fetcher,
        private readonly RaceFactsBuilder $factsBuilder,
        private readonly RaceChartsBuilder $chartsBuilder,
        private readonly RaceMomentsBuilder $momentsBuilder,
        private readonly LapTelemetryBuilder $telemetryBuilder,
        private readonly DriverResolver $drivers,
        private readonly RecapComposer $composer,
    ) {}

    /**
     * @return array{recap: ?RaceDataRecap, error: ?string}
     */
    public function generate(Race $race): array
    {
        $recap = RaceDataRecap::query()->firstOrNew(['race_id' => $race->id]);

        try {
            $keys = $this->resolver->resolve($race, $recap->exists ? $recap : null);

            if ($keys === null) {
                return $this->fail($recap, 'OpenF1 няма сесия, съответстваща на датата на състезанието.');
            }

            $bundle = $this->fetcher->fetch($keys);
            $facts = $this->factsBuilder->build($race, $bundle);

            if ($facts === null) {
                return $this->fail($recap, 'Данните не минаха санитарните граници — нищо не се публикува.');
            }

            $composed = $this->composer->compose($race, $facts);
            $facts['bullets'] = $composed['bullets'];
            $facts['narrative_by_llm'] = $composed['by_llm'];

            // Числата по пилот се закачат СЛЕД разказа — защо, виж
            // RaceFactsBuilder::perDriver.
            $perDriver = $this->factsBuilder->perDriver($bundle);

            if ($perDriver !== []) {
                $facts['per_driver'] = $perDriver;
            }

            // Телеметрията иска още четири заявки, затова се вади СЛЕД като е
            // ясно, че наборът е годен — счупен уикенд не бива да я плаща.
            $telemetry = $this->telemetryBuilder->build(
                $bundle,
                $keys,
                $this->drivers->map($race, $bundle->drivers),
                $keys->circuit,
                $keys->year,
            );

            $recap->fill([
                'race_id' => $race->id,
                'openf1_session_key' => $keys->race,
                'openf1_quali_session_key' => $keys->qualifying,
                'openf1_circuit_key' => $keys->circuit,
                'facts' => $facts,
                'charts' => array_filter([
                    ...$this->chartsBuilder->build($race, $bundle),
                    'telemetry' => $telemetry['telemetry'],
                    'track_map' => $telemetry['track_map'],
                    'moments' => $this->momentsBuilder->build($race, $bundle),
                ], fn ($value) => $value !== null && $value !== []),
                'headline' => $composed['headline'],
                'body_bg' => implode("\n\n", $composed['body']),
                'attempts' => 0,
                'last_error' => null,
                'generated_at' => now(),
            ])->save();

            return ['recap' => $recap->fresh(), 'error' => null];
        } catch (Throwable $e) {
            return $this->fail($recap, mb_substr($e->getMessage(), 0, 500));
        }
    }

    /**
     * @return array{recap: null, error: string}
     */
    private function fail(RaceDataRecap $recap, string $reason): array
    {
        $recap->fill([
            'attempts' => $recap->attempts + 1,
            'last_error' => $reason,
        ])->save();

        return ['recap' => null, 'error' => $reason];
    }
}
