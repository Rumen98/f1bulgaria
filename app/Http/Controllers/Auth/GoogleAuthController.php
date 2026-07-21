<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            // Отказано съгласие/изтекъл state — връщаме към login без драма.
            return redirect()->route('login')
                ->withErrors(['email' => 'Входът с Google не мина. Опитай отново.']);
        }

        $user = User::query()->where('google_id', $googleUser->getId())->first();

        if ($user === null) {
            // Свързваме по имейл, ако акаунт вече съществува (регистриран с парола) —
            // иначе същият човек би получил два акаунта.
            $user = User::query()->where('email', mb_strtolower($googleUser->getEmail()))->first();

            if ($user !== null) {
                $user->update(['google_id' => $googleUser->getId()]);
            } else {
                $user = User::create([
                    'name' => $googleUser->getName() ?: Str::before($googleUser->getEmail(), '@'),
                    'email' => mb_strtolower($googleUser->getEmail()),
                    'google_id' => $googleUser->getId(),
                    // Без парола (null): password-гейтнатите форми проверяват
                    // has_password и не искат текуща парола от Google акаунти.
                    'password' => null,
                ]);
            }
        }

        // Google вече е потвърдил имейла — не пращаме наш верификационен.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('home'));
    }
}
