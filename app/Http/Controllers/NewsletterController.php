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
     * Записва имейл за бюлетина. Имейл още НЕ се изпраща (SMTP не е конфигуриран) —
     * само съхраняваме като непотвърден с токен за по-късно double opt-in.
     */
    public function subscribe(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'in:homepage,footer,profile'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $subscriber = NewsletterSubscriber::query()->firstOrNew(['email' => $email]);

        if (! $subscriber->exists) {
            $subscriber->fill([
                'source' => $data['source'] ?? 'footer',
                'confirmation_token' => Str::random(48),
                'subscribed_at' => now(),
            ])->save();
        } elseif ($subscriber->unsubscribed_at !== null) {
            // Повторно абониране след отписване.
            $subscriber->update(['unsubscribed_at' => null, 'subscribed_at' => now()]);
        }

        return back()->with('success', 'Благодарим! Записахме имейла ти за бюлетина.');
    }

    public function confirm(string $token): RedirectResponse
    {
        $subscriber = NewsletterSubscriber::query()->where('confirmation_token', $token)->firstOrFail();

        if ($subscriber->confirmed_at === null) {
            $subscriber->update(['confirmed_at' => now()]);
        }

        return redirect()->route('home')->with('success', 'Абонаментът ти за бюлетина е потвърден!');
    }

    public function unsubscribe(string $token): RedirectResponse
    {
        $subscriber = NewsletterSubscriber::query()->where('confirmation_token', $token)->firstOrFail();

        $subscriber->update(['unsubscribed_at' => now()]);

        return redirect()->route('home')->with('success', 'Отписан си от бюлетина.');
    }
}
