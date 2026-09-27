<?php

use App\Http\Controllers\Admin\EventModerationController;
use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::get('events', [EventController::class, 'index'])->name('events.index');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('events', [EventModerationController::class, 'index'])->name('events.index');
        Route::post('events/{event}/approve', [EventModerationController::class, 'approve'])->name('events.approve');
        Route::post('events/{event}/reject', [EventModerationController::class, 'reject'])->name('events.reject');
        Route::post('events/{event}/revert', [EventModerationController::class, 'revert'])->name('events.revert');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
