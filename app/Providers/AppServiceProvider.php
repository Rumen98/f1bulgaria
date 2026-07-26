<?php

namespace App\Providers;

use App\Listeners\AuthEventSubscriber;
use App\Support\Seo;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Една инстанция на заявка — контролерите я пълнят, app.blade.php я чете.
        $this->app->scoped(Seo::class);
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
