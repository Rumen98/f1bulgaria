<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Създава/обновява админ акаунта от ADMIN_EMAIL + ADMIN_PASSWORD в .env.
 * Идемпотентна — пусни я след всяка промяна на креденшълите (паролата се
 * съхранява като bcrypt хеш през cast-а на модела, не в чист вид).
 */
class SyncAdminCommand extends Command
{
    protected $signature = 'padok:sync-admin';

    protected $description = 'Създава/обновява админ акаунта от ADMIN_EMAIL и ADMIN_PASSWORD в .env.';

    public function handle(): int
    {
        $email = (string) config('app.admin_email', '');
        $password = (string) config('app.admin_password', '');

        if ($email === '' || $password === '') {
            $this->error('Задай ADMIN_EMAIL и ADMIN_PASSWORD в .env (и презареди config кеша).');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => mb_strtolower(trim($email))]);
        $isNew = ! $user->exists;

        if ($isNew) {
            $user->name = 'Админ';
            // Env акаунтът не минава през регистрация — маркираме имейла като потвърден.
            $user->email_verified_at = now();
        }

        $user->password = $password;
        $user->is_admin = true;
        $user->banned_at = null;
        $user->save();

        $this->info($isNew
            ? "Създаден админ акаунт за {$user->email}."
            : "Обновен админ акаунт за {$user->email} (нова парола, is_admin=true).");

        return self::SUCCESS;
    }
}
