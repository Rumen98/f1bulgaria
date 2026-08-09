<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}
