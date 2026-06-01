<?php

declare(strict_types=1);

namespace App\Services\Jolpica;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Тънък клиент за Jolpica (Ergast-съвместим) F1 API.
 *
 * Връща винаги съдържанието на `MRData` като асоциативен масив и се грижи за
 * pagination-а (limit/offset) на endpoint-ите, които го изискват.
 *
 * @see https://api.jolpi.ca/ergast/f1/
 */
class JolpicaClient
{
    private const PAGE_SIZE = 100;

    private function request(): PendingRequest
    {
        /** @var array{base_url:string,timeout:int,retry_times:int,retry_sleep_ms:int} $config */
        $config = config('services.jolpica');

        return Http::baseUrl($config['base_url'])
            ->acceptJson()
            ->timeout($config['timeout'])
            ->retry($config['retry_times'], $config['retry_sleep_ms']);
    }

    /**
     * Изпълнява GET и връща `MRData` масива.
     *
     * @return array<string, mixed>
     */
    public function get(string $path, int $offset = 0): array
    {
        $response = $this->request()->get(ltrim($path, '/').'.json', [
            'limit' => self::PAGE_SIZE,
            'offset' => $offset,
        ]);

        $response->throw();

        /** @var array<string, mixed> $data */
        $data = $response->json('MRData', []);

        return $data;
    }

    /**
     * Изпълнява GET и събира всички страници от подадения списък.
     *
     * @param  string  $tableKey  ключът на таблицата в MRData (напр. "RaceTable")
     * @param  string  $listKey  ключът на списъка в таблицата (напр. "Races")
     * @return array<int, array<string, mixed>>
     */
    public function getAll(string $path, string $tableKey, string $listKey): array
    {
        $offset = 0;
        $items = [];

        do {
            $data = $this->get($path, $offset);
            $table = $data[$tableKey] ?? [];
            $page = $table[$listKey] ?? [];
            $items = array_merge($items, $page);

            $total = (int) ($data['total'] ?? 0);
            $offset += self::PAGE_SIZE;
        } while ($offset < $total);

        return $items;
    }

    /**
     * Календар (състезания) за сезон.
     *
     * @return array<int, array<string, mixed>>
     */
    public function races(int $year): array
    {
        return $this->getAll((string) $year, 'RaceTable', 'Races');
    }

    /**
     * Пилоти за сезон.
     *
     * @return array<int, array<string, mixed>>
     */
    public function drivers(int $year): array
    {
        return $this->getAll("{$year}/drivers", 'DriverTable', 'Drivers');
    }

    /**
     * Конструктори за сезон.
     *
     * @return array<int, array<string, mixed>>
     */
    public function constructors(int $year): array
    {
        return $this->getAll("{$year}/constructors", 'ConstructorTable', 'Constructors');
    }

    /**
     * Класиране на пилотите (съдържа текущия конструктор на всеки пилот).
     *
     * @return array<int, array<string, mixed>>
     */
    public function driverStandings(int $year): array
    {
        $data = $this->get("{$year}/driverstandings");
        $lists = $data['StandingsTable']['StandingsLists'] ?? [];

        return $lists[0]['DriverStandings'] ?? [];
    }

    /**
     * Резултати от едно състезание.
     *
     * @return array<int, array<string, mixed>>
     */
    public function results(int $year, int $round): array
    {
        $data = $this->get("{$year}/{$round}/results");
        $races = $data['RaceTable']['Races'] ?? [];

        return $races[0]['Results'] ?? [];
    }

    /**
     * Класиране от квалификацията (за определяне на pole).
     *
     * @return array<int, array<string, mixed>>
     */
    public function qualifying(int $year, int $round): array
    {
        $data = $this->get("{$year}/{$round}/qualifying");
        $races = $data['RaceTable']['Races'] ?? [];

        return $races[0]['QualifyingResults'] ?? [];
    }
}
