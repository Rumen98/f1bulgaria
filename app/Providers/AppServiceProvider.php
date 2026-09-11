<?php

namespace App\Providers;

use App\Listeners\AuthEventSubscriber;
use App\Services\News\Llm\AnthropicClient;
use App\Services\News\Llm\FallbackLlmClient;
use App\Services\News\Llm\LlmClient;
use App\Services\News\Llm\MistralClient;
use App\Support\Seo;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
            $primary = $this->makeLlmDriver($app, $driver);

            $fallbackDriver = (string) config('news.llm_fallback_driver', '');

            if ($fallbackDriver === '') {
                return $primary;
            }

            // Резервен, равен на основния, не е fallback — а мълчаливо
            // изключен fallback. Случва се точно при препоръчания авариен ход
            // (смяна на NEWS_LLM_DRIVER), затова оставя следа в лога вместо
            // да изчезне без дума.
            if ($fallbackDriver === $driver) {
                Log::warning("news.llm_fallback_driver съвпада с основния ({$driver}) — fallback-ът е неактивен.");

                return $primary;
            }

            return new FallbackLlmClient(
                $primary,
                $this->makeLlmDriver($app, $fallbackDriver),
                $driver,
                $fallbackDriver,
            );
        });
    }

    /**
     * @throws InvalidArgumentException при непознат драйвер
     */
    private function makeLlmDriver(Application $app, string $driver): LlmClient
    {
        return match ($driver) {
            'anthropic' => $app->make(AnthropicClient::class),
            'mistral' => $app->make(MistralClient::class),
            default => throw new InvalidArgumentException("Непознат LLM драйвер: {$driver}"),
        };
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

        // Глобален Reply-To на всяко изходящо писмо, включително Breeze
        // нотификациите (нулиране на парола) — те не минават през Mailable
        // класовете ни и биха останали без адрес за отговор.
        $replyTo = (string) config('mail.reply_to.address', '');

        if ($replyTo !== '') {
            Mail::alwaysReplyTo($replyTo, config('mail.reply_to.name'));
        }
    }
}
