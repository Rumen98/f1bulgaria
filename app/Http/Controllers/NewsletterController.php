<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class NewsletterController extends Controller
{
    /**
     * Записва имейл за бюлетина — директно активен, без потвърждаващ имейл.
     * Повторното записване е идемпотентно; отписан имейл се активира наново.
     */
    public function subscribe(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'in:homepage,footer,profile,article'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $subscriber = NewsletterSubscriber::query()->firstOrNew(['email' => $email]);

        if (! $subscriber->exists) {
            $subscriber->fill([
                'source' => $data['source'] ?? 'footer',
                'unsubscribe_token' => Str::random(48),
                'subscribed_at' => now(),
            ])->save();
        } elseif ($subscriber->unsubscribed_at !== null) {
            // Повторно абониране след отписване.
            $subscriber->update(['unsubscribed_at' => null, 'subscribed_at' => now()]);
        }

        return back()->with('success', 'Благодарим! Записахме те за бюлетина.');
    }

    /**
     * Legacy: обслужва линкове от вече изпратени потвърждаващи имейли
     * (double opt-in е премахнат). Може да се махне след няколко месеца.
     */
    public function confirm(string $token): RedirectResponse
    {
        $subscriber = NewsletterSubscriber::query()->where('unsubscribe_token', $token)->firstOrFail();

        // Кликът е изричен opt-in (токенът доказва достъп до пощата) —
        // активира наново дори отписан абонат.
        if ($subscriber->unsubscribed_at !== null) {
            $subscriber->update(['unsubscribed_at' => null, 'subscribed_at' => now()]);
        }

        return redirect()->route('home')->with('success', 'Абонаментът ти за бюлетина е активен!');
    }

    public function unsubscribe(string $token): RedirectResponse
    {
        $subscriber = NewsletterSubscriber::query()->where('unsubscribe_token', $token)->firstOrFail();

        $subscriber->update(['unsubscribed_at' => now()]);

        return redirect()->route('home')->with('success', 'Отписан си от бюлетина.');
    }

    /**
     * Спира имейлите от Падок за потребител с акаунт. Линкът е signed URL
     * от футъра на всяко писмо — валидността се гарантира от 'signed'
     * middleware-а, без нужда от вход.
     */
    public function userUnsubscribe(User $user): RedirectResponse
    {
        $user->forceFill(['email_opt_out_at' => now()])->save();

        return redirect()->route('home')->with('success', 'Спряхме имейлите от Падок към този адрес.');
    }

    /**
     * One-click отписване на абонат (RFC 8058) — POST от пощенския доставчик.
     *
     * Отговорът е 200, не пренасочване: доставчикът не следва redirect-и и
     * третира всичко извън 2xx като счупено отписване, което вреди на
     * репутацията повече от липсващ хедър. По същата причина непознат токен
     * също връща 200 — повторното натискане на „Отписване" не е грешка.
     */
    public function unsubscribeOneClick(string $token): Response
    {
        NewsletterSubscriber::query()
            ->where('unsubscribe_token', $token)
            ->whereNull('unsubscribed_at')
            ->update(['unsubscribed_at' => now()]);

        return $this->oneClickAck();
    }

    /**
     * One-click спиране на всички имейли за потребител с акаунт. Валидността
     * идва от 'signed' middleware-а — подписът е в query string-а и оцелява
     * при POST.
     */
    public function userUnsubscribeOneClick(User $user): Response
    {
        $user->forceFill(['email_opt_out_at' => now()])->save();

        return $this->oneClickAck();
    }

    private function oneClickAck(): Response
    {
        return response('OK', 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
