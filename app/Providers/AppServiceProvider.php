<?php

namespace App\Providers;

use App\Listeners\AuthEventSubscriber;
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
        //
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
