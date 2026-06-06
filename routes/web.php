<?php

declare(strict_types=1);

use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CircuitsController;
use App\Http\Controllers\CompareController;
use App\Http\Controllers\DriversController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LiveTimingController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PredictionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\RaceController;
use App\Http\Controllers\RivalriesController;
use App\Http\Controllers\StandingsController;
use App\Http\Controllers\TeamsController;
use App\Http\Controllers\TsolovController;
use Illuminate\Support\Facades\Route;

// Публични страници.
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/live', [LiveTimingController::class, 'index'])->name('live');
Route::get('/live/refresh', [LiveTimingController::class, 'refresh'])->name('live.refresh');
Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
Route::get('/calendar.ics', [CalendarController::class, 'ics'])->name('calendar.ics');
Route::get('/calendar/team/{slug}.ics', [CalendarController::class, 'teamIcs'])->where('slug', '[a-z0-9_-]+')->name('calendar.team.ics');
Route::get('/calendar/{slug}.ics', [CalendarController::class, 'driverIcs'])->where('slug', '[a-z0-9_-]+')->name('calendar.driver.ics');
Route::get('/standings', [StandingsController::class, 'index'])->name('standings');
Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard');
Route::get('/races/{race}', [RaceController::class, 'show'])->name('races.show');
Route::get('/teams', [TeamsController::class, 'index'])->name('teams.index');
Route::get('/teams/{slug}', [TeamsController::class, 'show'])->name('teams.show');
Route::get('/drivers', [DriversController::class, 'index'])->name('drivers.index');
Route::get('/drivers/{slug}', [DriversController::class, 'show'])->name('drivers.show');
Route::get('/circuits', [CircuitsController::class, 'index'])->name('circuits.index');
Route::get('/circuits/{slug}', [CircuitsController::class, 'show'])->name('circuits.show');
Route::get('/compare', [CompareController::class, 'index'])->name('compare.index');
Route::get('/compare/{slug1}/{slug2}', [CompareController::class, 'show'])->name('compare.show');
Route::get('/tsolov', [TsolovController::class, 'index'])->name('tsolov');
Route::get('/istoria', [HistoryController::class, 'index'])->name('history');
Route::get('/istoria/svetovna', [HistoryController::class, 'world'])->name('history.world');
Route::get('/istoria/bulgaria', [HistoryController::class, 'bulgaria'])->name('history.bulgaria');
Route::get('/rivalries', [RivalriesController::class, 'index'])->name('rivalries.index');
Route::middleware('auth')->group(function () {
    Route::get('/rivalries/create', [RivalriesController::class, 'create'])->name('rivalries.create');
    Route::post('/rivalries', [RivalriesController::class, 'store'])->name('rivalries.store');
});
Route::get('/rivalries/{slug}', [RivalriesController::class, 'show'])->name('rivalries.show');
Route::get('/news', [NewsController::class, 'index'])->name('news.index');
Route::get('/news/{slug}', [NewsController::class, 'show'])->name('news.show');
Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])->name('newsletter.subscribe');
Route::get('/newsletter/confirm/{token}', [NewsletterController::class, 'confirm'])->name('newsletter.confirm');
Route::get('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'])->name('newsletter.unsubscribe');
Route::get('/profiles/{user}', [PublicProfileController::class, 'show'])->name('profiles.show');

Route::get('/dashboard', [CalendarController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Изисква вход.
Route::middleware('auth')->group(function () {
    Route::get('/predictions', [PredictionController::class, 'index'])->name('predictions.index');
    Route::post('/races/{race}/prediction', [PredictionController::class, 'store'])->name('predictions.store');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
