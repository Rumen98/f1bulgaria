<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AuthEvent;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;

/**
 * Записва събитията от автентикацията в auth_events (одит лог).
 * Регистриран в AppServiceProvider чрез Event::subscribe().
 */
class AuthEventSubscriber
{
    public function __construct(private readonly Request $request) {}

    public function handleRegistered(Registered $event): void
    {
        $this->record(AuthEvent::TYPE_REGISTERED, $event->user);
    }

    public function handleLogin(Login $event): void
    {
        $this->record(AuthEvent::TYPE_LOGIN, $event->user);
    }

    public function handleLogout(Logout $event): void
    {
        $this->record(AuthEvent::TYPE_LOGOUT, $event->user);
    }

    public function handleFailed(Failed $event): void
    {
        // Неуспешен вход: обикновено няма съвпаднал потребител — вадим имейла
        // от подадените креденшъли, за да видим кой акаунт е бил атакуван.
        AuthEvent::create([
            'user_id' => $event->user?->getAuthIdentifier(),
            'email' => $event->credentials['email'] ?? null,
            'type' => AuthEvent::TYPE_FAILED,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Registered::class => 'handleRegistered',
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
        ];
    }

    private function record(string $type, mixed $user): void
    {
        AuthEvent::create([
            'user_id' => $user?->getAuthIdentifier(),
            'email' => $user->email ?? null,
            'type' => $type,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }
}
