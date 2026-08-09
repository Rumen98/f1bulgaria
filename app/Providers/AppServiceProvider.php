<?php

namespace App\Providers;

use App\Listeners\AuthEventSubscriber;
use App\Services\News\Llm\AnthropicClient;
use App\Services\News\Llm\LlmClient;
use App\Services\News\Llm\MistralClient;
use App\Support\Seo;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Една инстанция на заявка — контролерите я пълнят, app.blade.php я чете.
        $this->app->scoped(Seo::class);

        // LLM доставчикът за news pipeline-а се избира от конфигурация, за да
        // може смяната (напр. при изчерпан Anthropic бюджет) да е .env промяна.
        $this->app->bind(LlmClient::class, function (Application $app): LlmClient {
            $driver = (string) config('news.llm_driver', 'anthropic');

            return match ($driver) {
                'anthropic' => $app->make(AnthropicClient::class),
                'mistral' => $app->make(MistralClient::class),
                default => throw new InvalidArgumentException("Непознат news.llm_driver: {$driver}"),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Без "data" обвивка — ресурсите се подават директно като Inertia props.
        JsonResource::withoutWrapping();

        // Одит лог на автентикацията (регистрации, влизания, изходи, неуспешни опити).
        Event::subscribe(AuthEventSubscriber::class);
    }
}
