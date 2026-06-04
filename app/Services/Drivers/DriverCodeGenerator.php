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
 * Идентичността е по ПЪЛНО име (име + фамилия) — различни хора НИКОГА не споделят
 * код. Един и същ пилот през няколко сезона получава един код. Кодове, идващи от
 * Ergast (source of truth), не се пренаписват тук.
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
            /** @var array<string, true> $taken  кодове, които вече са заети */
            $taken = [];
            /** @var array<string, string> $identityToCode  пълно име => код (за същия човек през сезони) */
            $identityToCode = [];

            // Сеем със съществуващите кодове — за да не ги дублираме (вкл. Ergast).
            Driver::query()
                ->whereNotNull('driver_code')
                ->where('driver_code', '!=', '')
                ->get(['first_name', 'last_name', 'driver_code'])
                ->each(function (Driver $d) use (&$taken, &$identityToCode): void {
                    $identityToCode[$this->identityKey($d->first_name, $d->last_name)] = $d->driver_code;
                    $taken[$d->driver_code] = true;
                });

            $stats = ['updated' => 0, 'generated' => 0, 'reused' => 0, 'collisions' => 0, 'samples' => []];

            $pending = Driver::query()
                ->where(fn ($q) => $q->whereNull('driver_code')->orWhere('driver_code', ''))
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->orderBy('season_id')
                ->get();

            foreach ($pending as $driver) {
                $identity = $this->identityKey($driver->first_name, $driver->last_name);

                // Reuse САМО за същия човек (същото пълно име) — не по фамилия.
                if (isset($identityToCode[$identity])) {
                    $code = $identityToCode[$identity];
                    $stats['reused']++;
                } else {
                    $firstChoice = substr($this->asciiUpper($driver->last_name), 0, 3);
                    $code = $this->uniqueCodeFor($driver->first_name, $driver->last_name, $taken);
                    $identityToCode[$identity] = $code;
                    $taken[$code] = true;
                    $stats['generated']++;
                    $stats['collisions'] += $code === $firstChoice ? 0 : 1;
                    $stats['samples'][trim("{$driver->first_name} {$driver->last_name}")] = $code;
                }

                $driver->update(['driver_code' => $code]);
                $stats['updated']++;
            }

            return $stats;
        });
    }

    /**
     * Генерира уникален код за пилот, избягвайки всички подадени заети кодове.
     *
     * @param  array<string, mixed>  $taken  карта код => caквото и да е (за O(1) проверка)
     */
    public function uniqueCodeFor(?string $first, ?string $last, array $taken): string
    {
        $l = $this->asciiUpper($last);
        $f = $this->asciiUpper($first);

        $candidates = array_values(array_filter([
            substr($l, 0, 3),                          // SEN
            substr($l, 0, 2).substr($f, 0, 1),         // SEB
            substr($l, 0, 3).substr($f, 0, 1),         // SENB
            substr($l, 0, 2).substr($f, 0, 2),         // SEBR
        ], fn ($c) => strlen($c) >= 2));

        foreach ($candidates as $candidate) {
            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }

        $base = substr($l.'XXX', 0, 3);
        $n = 1;
        while (isset($taken["{$base}{$n}"])) {
            $n++;
        }

        return "{$base}{$n}";
    }

    /**
     * Ключ за идентичност — нормализирано пълно име (различни хора → различни ключове).
     */
    public function identityKey(?string $first, ?string $last): string
    {
        return Str::lower(Str::ascii(trim("{$first} {$last}")));
    }

    private function asciiUpper(?string $value): string
    {
        return Str::upper(preg_replace('/[^A-Za-z]/', '', Str::ascii(trim((string) $value))) ?? '');
    }
}
