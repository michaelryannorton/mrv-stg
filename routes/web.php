<?php

use App\Http\Controllers\Admin\ChannelController;
use App\Http\Controllers\Admin\ClipController;
use App\Http\Controllers\Admin\EventEditController;
use App\Http\Controllers\Admin\EventModerationController;
use App\Http\Controllers\Admin\SubmissionModerationController;
use App\Http\Controllers\Admin\UserAssignmentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ChannelPageController;
use App\Http\Controllers\CurateController;
use App\Http\Controllers\CuratorProfileController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\PublicSubmissionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

// Local environment only: the port's component sheet (port/phase-1), compared with production's look. Never
// registered on staging, on production or under test.
if (app()->environment('local')) {
    Route::get('port/components', function (Request $request) {
        $season = $request->query('season');

        return Inertia::render('port/components', [
            // Production's markup, with its one renamed class (`container` is `mrv-container` here, see mrv.css).
            'html' => preg_replace_callback(
                '/class="([^"]*)"/',
                fn (array $m) => 'class="'.preg_replace('/(?<![\w-])container(?![\w-])/', 'mrv-container', $m[1]).'"',
                file_get_contents(base_path('../port/phase-1/reference/sheet-body.html')),
            ),
            'season' => in_array($season, ['winter', 'spring', 'summer', 'fall'], true) ? $season : null,
        ]);
    })->name('port.components');

    // The map and chart wrappers (port phase 2), compared with production's own map.js and CDN libraries.
    Route::get('port/maps-charts', fn () => Inertia::render('port/maps-charts', [
        'inputs' => json_decode(file_get_contents(base_path('../port/phase-2/reference/inputs.json')), true),
    ]))->name('port.maps-charts');
}

Route::get('events', [EventController::class, 'index'])->name('events.index');

// Public channel pages (Phase 2 slice 1d-ii). Only active channels are public; an administrator
// can preview the others at the same address.
Route::get('channels', [ChannelPageController::class, 'index'])->name('channels.index');
Route::get('channels/{channel:slug}', [ChannelPageController::class, 'show'])->name('channels.show');

// Open to anonymous visitors, so both routes are rate limited (AppServiceProvider::configurePublicSubmissionLimits).
Route::get('submit-event', [PublicSubmissionController::class, 'create'])->middleware('throttle:submission-lookups')->name('submit.create');
Route::post('submit-event', [PublicSubmissionController::class, 'store'])->middleware('throttle:submissions')->name('submit.store');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    // The curator workspace (Phase 2 slice 1c-ii). Needs the channels.curate permission; every
    // action is then authorized per channel by ChannelPolicy, which answers 404 for channels the
    // user is not assigned to.
    Route::middleware('permission:channels.curate')->prefix('curate')->name('curate.')->group(function () {
        Route::get('/', [CurateController::class, 'index'])->name('index');
        Route::get('channels/{channel:slug}', [CurateController::class, 'show'])->name('channels.show');
        Route::post('channels/{channel:slug}/events/{event}/{action}', [CurateController::class, 'act'])
            ->whereNumber('event')
            ->where('action', 'feature|unfeature|exclude|restore|add')
            ->name('channels.act');
    });

    // The signed-in user's own public profile (Phase 2 slice 1c-iii). No id in the URL.
    Route::middleware('permission:profile.edit')->group(function () {
        Route::get('curate/profile', [CuratorProfileController::class, 'edit'])->name('curate.profile.edit');
        Route::put('curate/profile', [CuratorProfileController::class, 'update'])->name('curate.profile.update');
    });

    // Every /admin route is administrator-only. Curators and editors get their own /curate pages
    // (Phase 2 slice 1c) and never reach these; see community/Community Phase 2 Slice 1 Plan.md.
    Route::prefix('admin')->name('admin.')->middleware('role:administrator')->group(function () {
        Route::get('events', [EventModerationController::class, 'index'])->name('events.index');
        Route::post('events/bulk', [EventModerationController::class, 'bulk'])->name('events.bulk');
        Route::get('events/{event}/edit', [EventEditController::class, 'edit'])->name('events.edit');
        Route::put('events/{event}', [EventEditController::class, 'update'])->name('events.update');
        Route::post('events/{event}/sync-field', [EventEditController::class, 'syncField'])->name('events.sync-field');

        Route::get('clip', [ClipController::class, 'create'])->name('clip.create');
        Route::post('clip', [ClipController::class, 'store'])->name('clip.store');
        Route::get('drafts', [ClipController::class, 'drafts'])->name('drafts.index');
        Route::post('drafts/{event}/publish', [ClipController::class, 'publishDraft'])->name('drafts.publish');

        Route::get('channels', [ChannelController::class, 'index'])->name('channels.index');
        Route::get('channels/create', [ChannelController::class, 'create'])->name('channels.create');
        Route::post('channels', [ChannelController::class, 'store'])->name('channels.store');
        Route::get('channels/{channel}/edit', [ChannelController::class, 'edit'])->name('channels.edit');
        Route::put('channels/{channel}', [ChannelController::class, 'update'])->name('channels.update');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/resend', [UserController::class, 'resend'])->name('users.resend');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('users/{user}/remove', [UserController::class, 'remove'])->name('users.remove');
        Route::post('users/{user}/assignments', [UserAssignmentController::class, 'store'])->name('users.assignments.store');
        Route::put('users/{user}/assignments/{channel}', [UserAssignmentController::class, 'update'])->name('users.assignments.update');
        Route::delete('users/{user}/assignments/{channel}', [UserAssignmentController::class, 'destroy'])->name('users.assignments.destroy');

        Route::get('submissions', [SubmissionModerationController::class, 'index'])->name('submissions.index');
        Route::post('submissions/bulk', [SubmissionModerationController::class, 'bulk'])->name('submissions.bulk');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
