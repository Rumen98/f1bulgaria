<?php

declare(strict_types=1);

namespace App\Services\Drivers;

use App\Models\Driver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Генерира driver_code за историческите пилоти (Ergast не е попълвал кодове
 * pre-2006), за да участват в групираните по код all-time класирания.
 *
 * Логика (hybrid):
 *  - един и същ пилот (по нормализирано име) получава ЕДИН код през всички сезони
 *    (вкл. ако вече има код в някой сезон — преходът ~2000-2006);
 *  - иначе генерира от фамилията с разрешаване на колизии.
 * Идемпотентно — повторно изпълнение не променя нищо.
 */
class DriverCodeGenerator
{
    /**
     * @return array{updated:int, generated:int, reused:int, collisions:int, samples:array<string, string>}
     */
    public function assignAll(): array
    {
        return DB::transaction(function () {
            /** @var array<string, string> $codeOwner  код => нормализирано име на собственика */
            $codeOwner = [];
            /** @var array<string, string> $nameToCode  нормализирано име => код */
            $nameToCode = [];

            // Сеем с вече съществуващите кодове — за reuse и засичане на колизии.
            Driver::query()
                ->whereNotNull('driver_code')
                ->where('driver_code', '!=', '')
                ->get(['first_name', 'last_name', 'driver_code'])
                ->each(function (Driver $d) use (&$codeOwner, &$nameToCode): void {
                    $name = $this->normalizeName($d->first_name, $d->last_name);
                    $nameToCode[$name] = $d->driver_code;
                    $codeOwner[$d->driver_code] = $name;
                });

            $stats = ['updated' => 0, 'generated' => 0, 'reused' => 0, 'collisions' => 0, 'samples' => []];

            $pending = Driver::query()
                ->where(fn ($q) => $q->whereNull('driver_code')->orWhere('driver_code', ''))
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->orderBy('season_id')
                ->get();

            foreach ($pending as $driver) {
                $name = $this->normalizeName($driver->first_name, $driver->last_name);

                if (isset($nameToCode[$name])) {
                    $code = $nameToCode[$name];
                    $stats['reused']++;
                } else {
                    [$code, $collided] = $this->generateUniqueCode($driver, $codeOwner);
                    $nameToCode[$name] = $code;
                    $codeOwner[$code] = $name;
                    $stats['generated']++;
                    $stats['collisions'] += $collided ? 1 : 0;
                    $stats['samples'][trim("{$driver->first_name} {$driver->last_name}")] = $code;
                }

                $driver->update(['driver_code' => $code]);
                $stats['updated']++;
            }

            return $stats;
        });
    }

    /**
     * @param  array<string, string>  $codeOwner
     * @return array{0:string, 1:bool} [код, имало ли е колизия]
     */
    private function generateUniqueCode(Driver $driver, array $codeOwner): array
    {
        $last = $this->asciiUpper($driver->last_name);
        $first = $this->asciiUpper($driver->first_name);

        $candidates = array_values(array_filter([
            substr($last, 0, 3),                            // FAN
            substr($last, 0, 2).substr($first, 0, 1),       // FAJ
            substr($last, 0, 2).substr($first, 0, 2),       // FAJU
        ], fn ($c) => strlen($c) >= 2));

        $firstChoice = $candidates[0] ?? 'XXX';

        foreach ($candidates as $candidate) {
            if (! isset($codeOwner[$candidate])) {
                return [$candidate, $candidate !== $firstChoice];
            }
        }

        // Накрая: първи 3 букви + пореден номер.
        $base = substr($last.'XXX', 0, 3);
        $n = 2;
        while (isset($codeOwner["{$base}{$n}"])) {
            $n++;
        }

        return ["{$base}{$n}", true];
    }

    private function normalizeName(?string $first, ?string $last): string
    {
        return Str::lower(Str::ascii(trim("{$first} {$last}")));
    }

    private function asciiUpper(?string $value): string
    {
        return Str::upper(preg_replace('/[^A-Za-z]/', '', Str::ascii(trim((string) $value))) ?? '');
    }
}
