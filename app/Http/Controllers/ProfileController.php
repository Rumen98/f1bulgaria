<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Constructor;
use App\Models\Driver;
use App\Models\Season;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $season = Season::current();
        $seasonId = $season?->id;

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            // Google акаунтите нямат парола — формите за смяна/изтриване се съобразяват.
            'hasPassword' => $request->user()->password !== null,
            'drivers' => $seasonId
                ? Driver::query()->where('season_id', $seasonId)
                    ->orderBy('last_name')->get(['id', 'first_name', 'last_name'])
                    ->map(fn ($d) => ['id' => $d->id, 'name' => $d->fullName()])
                : [],
            'constructors' => $seasonId
                ? Constructor::query()->where('season_id', $seasonId)
                    ->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Google акаунт без парола потвърждава без нея — няма какво да въведе.
        if ($request->user()->password !== null) {
            $request->validate([
                'password' => ['required', 'current_password'],
            ]);
        }

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
