<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Маркира значките на влезлия като видени — вика се, когато затвори
 * поздравителния тост (или отвори профила си от него).
 */
class BadgeSeenController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        DB::table('badge_user')
            ->where('user_id', $request->user()->id)
            ->whereNull('seen_at')
            ->update(['seen_at' => now()]);

        return back();
    }
}
