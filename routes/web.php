<?php

declare(strict_types=1);

use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CircuitsController;
use App\Http\Controllers\CommentsController;
use App\Http\Controllers\CompareController;
use App\Http\Controllers\DriversController;
use App\Http\Controllers\F2CalendarController;
use App\Http\Controllers\F2Controller;
use App\Http\Controllers\F2DriversController;
use App\Http\Controllers\F2RaceController;
use App\Http\Controllers\F2TeamsController;
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
use App\Http\Controllers\StaticPageController;
use App\Http\Controllers\TeamsController;
use App\Http\Controllers\TerminologyController;
use App\Http\Controllers\TsolovController;
use Illuminate\Support\Facades\Route;

// Публични страници (V1 launch scope).
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
Route::get('/calendar.ics', [CalendarController::class, 'ics'])->name('calendar.ics');
Route::get('/calendar/team/{slug}.ics', [CalendarController::class, 'teamIcs'])->where('slug', '[a-z0-9_-]+')->name('calendar.team.ics');
Route::get('/calendar/{slug}.ics', [CalendarController::class, 'driverIcs'])->where('slug', '[a-z0-9_-]+')->name('calendar.driver.ics');
Route::get('/standings', [StandingsController::class, 'index'])->name('standings');
Route::get('/standings/{year}', [StandingsController::class, 'index'])->where('year', '[0-9]{4}')->name('standings.year');
Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard');
Route::get('/races/{race}', [RaceController::class, 'show'])->name('races.show');
Route::get('/teams', [TeamsController::class, 'index'])->name('teams.index');
Route::get('/teams/{slug}', [TeamsController::class, 'show'])->name('teams.show');
Route::get('/drivers', [DriversController::class, 'index'])->name('drivers.index');
Route::get('/drivers/{slug}', [DriversController::class, 'show'])->name('drivers.show');
Route::get('/terminologiya', [TerminologyController::class, 'index'])->name('terminology');
Route::get('/poveritelnost', [StaticPageController::class, 'privacy'])->name('privacy');
Route::get('/usloviya', [StaticPageController::class, 'terms'])->name('terms');
Route::get('/kontakt', [StaticPageController::class, 'contact'])->name('contact');

// V2+ модули — завършени, но скрити за launch (config/features.php). Кодът
// остава непокътнат; рутовете връщат 404 докато флагът е изключен. Включи през
// .env (напр. FEATURE_F2=true) — без code change. Навигацията също чете флаговете.
Route::middleware('feature:live_timing')->group(function () {
    Route::get('/live', [LiveTimingController::class, 'index'])->name('live');
    Route::get('/live/refresh', [LiveTimingController::class, 'refresh'])->name('live.refresh');
});

Route::middleware('feature:circuits')->group(function () {
    Route::get('/circuits', [CircuitsController::class, 'index'])->name('circuits.index');
    Route::get('/circuits/{slug}', [CircuitsController::class, 'show'])->name('circuits.show');
});

Route::middleware('feature:compare')->group(function () {
    Route::get('/compare', [CompareController::class, 'index'])->name('compare.index');
    Route::get('/compare/{slug1}/{slug2}', [CompareController::class, 'show'])->name('compare.show');
});

Route::middleware('feature:tsolov')->group(function () {
    Route::get('/tsolov', [TsolovController::class, 'index'])->name('tsolov');
});

Route::middleware('feature:f2')->group(function () {
    Route::get('/f2', [F2Controller::class, 'index'])->name('f2');
    Route::get('/f2/calendar', [F2CalendarController::class, 'index'])->name('f2.calendar');
    Route::get('/f2/calendar/{year}', [F2CalendarController::class, 'index'])->where('year', '[0-9]{4}')->name('f2.calendar.year');
    Route::get('/f2/drivers', [F2DriversController::class, 'index'])->name('f2.drivers.index');
    Route::get('/f2/drivers/{slug}', [F2DriversController::class, 'show'])->name('f2.drivers.show');
    Route::get('/f2/teams', [F2TeamsController::class, 'index'])->name('f2.teams.index');
    Route::get('/f2/teams/{slug}', [F2TeamsController::class, 'show'])->name('f2.teams.show');
    Route::get('/f2/races/{race}/{session}', [F2RaceController::class, 'show'])->where('session', 'sprint|feature')->name('f2.race');
    Route::get('/f2/seasons/{year}', [F2Controller::class, 'season'])->where('year', '[0-9]{4}')->name('f2.season');
});

Route::middleware('feature:history')->group(function () {
    Route::get('/istoria', [HistoryController::class, 'index'])->name('history');
    Route::get('/istoria/svetovna', [HistoryController::class, 'world'])->name('history.world');
    Route::get('/istoria/bulgaria', [HistoryController::class, 'bulgaria'])->name('history.bulgaria');
});

Route::middleware('feature:rivalries')->group(function () {
    Route::get('/rivalries', [RivalriesController::class, 'index'])->name('rivalries.index');
    Route::middleware('auth')->group(function () {
        Route::get('/rivalries/create', [RivalriesController::class, 'create'])->name('rivalries.create');
        Route::post('/rivalries', [RivalriesController::class, 'store'])->name('rivalries.store');
    });
    Route::get('/rivalries/{slug}', [RivalriesController::class, 'show'])->name('rivalries.show');
});

Route::get('/news', [NewsController::class, 'index'])->name('news.index');
Route::get('/news/{slug}', [NewsController::class, 'show'])->name('news.show');
Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/news/{slug}/comments', [CommentsController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('news.comments.store');
    Route::delete('/comments/{comment}', [CommentsController::class, 'destroy'])->name('comments.destroy');
});
Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])
    ->middleware('throttle:5,1')
    ->name('newsletter.subscribe');
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
