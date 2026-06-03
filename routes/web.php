<?php

declare(strict_types=1);

use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CircuitsController;
use App\Http\Controllers\DriversController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\PredictionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\RaceController;
use App\Http\Controllers\StandingsController;
use App\Http\Controllers\TeamsController;
use Illuminate\Support\Facades\Route;

// Публични страници.
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
Route::get('/standings', [StandingsController::class, 'index'])->name('standings');
Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard');
Route::get('/races/{race}', [RaceController::class, 'show'])->name('races.show');
Route::get('/teams', [TeamsController::class, 'index'])->name('teams.index');
Route::get('/teams/{slug}', [TeamsController::class, 'show'])->name('teams.show');
Route::get('/drivers', [DriversController::class, 'index'])->name('drivers.index');
Route::get('/drivers/{slug}', [DriversController::class, 'show'])->name('drivers.show');
Route::get('/circuits', [CircuitsController::class, 'index'])->name('circuits.index');
Route::get('/circuits/{slug}', [CircuitsController::class, 'show'])->name('circuits.show');
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
