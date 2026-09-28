<?php

use App\Http\Controllers\Admin\ClipController;
use App\Http\Controllers\Admin\EventEditController;
use App\Http\Controllers\Admin\EventModerationController;
use App\Http\Controllers\Admin\SubmissionModerationController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\PublicSubmissionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::get('events', [EventController::class, 'index'])->name('events.index');

Route::get('submit-event', [PublicSubmissionController::class, 'create'])->name('submit.create');
Route::post('submit-event', [PublicSubmissionController::class, 'store'])->name('submit.store');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('events', [EventModerationController::class, 'index'])->name('events.index');
        Route::post('events/bulk', [EventModerationController::class, 'bulk'])->name('events.bulk');
        Route::get('events/{event}/edit', [EventEditController::class, 'edit'])->name('events.edit');
        Route::put('events/{event}', [EventEditController::class, 'update'])->name('events.update');
        Route::post('events/{event}/sync-field', [EventEditController::class, 'syncField'])->name('events.sync-field');

        Route::get('clip', [ClipController::class, 'create'])->name('clip.create');
        Route::post('clip', [ClipController::class, 'store'])->name('clip.store');
        Route::get('drafts', [ClipController::class, 'drafts'])->name('drafts.index');
        Route::post('drafts/{event}/publish', [ClipController::class, 'publishDraft'])->name('drafts.publish');

        Route::get('submissions', [SubmissionModerationController::class, 'index'])->name('submissions.index');
        Route::post('submissions/bulk', [SubmissionModerationController::class, 'bulk'])->name('submissions.bulk');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
