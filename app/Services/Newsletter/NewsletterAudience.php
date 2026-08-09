<?php

declare(strict_types=1);

namespace App\Services\Newsletter;

use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Получатели на бюлетинните имейли (дайджест, preview, пулс): всички
 * небанати потребители + активните абонати без акаунт. Дедупликацията е
 * по имейл — потребител, който е и абонат, получава само личната версия.
 */
class NewsletterAudience
{
    /**
     * @return Collection<int, User>
     */
    public function users(): Collection
    {
        return User::query()
            ->whereNull('banned_at')
            ->whereNull('email_opt_out_at')
            ->get();
    }

    /**
     * Активни абонати, чийто имейл не принадлежи на подадените потребители.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, NewsletterSubscriber>
     */
    public function subscribersWithoutAccount(Collection $users): Collection
    {
        $userEmails = $users->pluck('email')
            ->map(fn (string $email) => mb_strtolower($email))
            ->all();

        return NewsletterSubscriber::active()
            ->whereNotIn('email', $userEmails)
            ->get();
    }
}
