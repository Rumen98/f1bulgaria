<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Race;
use App\Services\Og\CircuitOgImage;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Картинките за социалните карти, рисувани от собствените данни.
 *
 * Отделен маршрут, а не файл, генериран при публикуване: рекапите се
 * преизчисляват при наваксване назад и картинка, залепена в момента на
 * публикуване, тихо остарява. Тук се рисува при първото поискване и се пази
 * на диска — формата на пистата не се мени.
 */
class OgImageController extends Controller
{
    /** Скрейпърите се връщат често, а картинката е една и съща. */
    private const MAX_AGE = 2592000;

    public function __construct(private readonly CircuitOgImage $circuits) {}

    public function circuit(Race $race): Response
    {
        $recap = $race->dataRecap()->ready()->first(['id', 'race_id', 'charts', 'generated_at']);

        if ($recap === null) {
            throw new NotFoundHttpException;
        }

        $path = $this->path($race, $recap->generated_at?->getTimestamp() ?? 0);
        $disk = Storage::disk('local');
        $png = $disk->get($path);

        if ($png === null) {
            $png = $this->circuits->render($recap->charts['track_map'] ?? null);

            if ($png === null) {
                // Рекап без карта на трасето — страницата пази общия банер и
                // изобщо не сочи насам.
                throw new NotFoundHttpException;
            }

            $disk->put($path, $png);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age='.self::MAX_AGE,
        ]);
    }

    /**
     * Моментът на генериране влиза в името на файла: наваксването назад
     * преизчислява стари кръгове и картата може да смени пилота, а адресът
     * остава същият — иначе Facebook би държал стария кадър завинаги.
     */
    private function path(Race $race, int $generatedAt): string
    {
        return sprintf('og/circuits/%d-%d.png', $race->id, $generatedAt);
    }
}
