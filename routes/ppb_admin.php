<?php

use App\Http\Controllers\Ppb\AdminController;
use App\Http\Controllers\Ppb\AccountController;
use App\Http\Controllers\Ppb\RoleController;
use App\Http\Controllers\Ppb\AnalystController;
use App\Http\Controllers\Ppb\GameController;
use App\Http\Controllers\Ppb\AssignmentController;
use App\Http\Controllers\Ppb\ManuscriptController;
use App\Http\Middleware\PpbCan;
use App\Http\Middleware\PpbEnsureActive;
use App\Http\Middleware\PpbRequireSecureHost;
use Illuminate\Support\Facades\Route;

// PPB_V2_ROUTE_GATE_20261104
// Public previews are retired; preserve named routes.
Route::redirect('/admin/preview', 'https://v2.csdatw.xyz/admin', 302)->name('ppb.preview');
Route::redirect('/admin/preview/accounts', 'https://v2.csdatw.xyz/admin/accounts', 302)->name('ppb.preview.accounts');
Route::redirect('/admin/preview/operations', 'https://v2.csdatw.xyz/admin/games', 302)->name('ppb.preview.operations');
Route::get('/admin/login', [AdminController::class, 'loginForm'])->name('login');
Route::post('/admin/login', [AdminController::class, 'login'])
    ->middleware([PpbRequireSecureHost::class, 'throttle:6,1'])
    ->name('ppb.login');

Route::prefix('admin')->middleware([
    PpbRequireSecureHost::class,
    'auth:ppb',
    PpbEnsureActive::class,
])->group(function () {
    // PPB_UI3_20261104 | No separate dashboard; keep the named route for compatibility.
    // Access to the schedule homepage still requires HTTPS, login, an active account and permission.
    Route::get('/', fn () => redirect()->route('ppb.games.index'))
        ->middleware(PpbCan::class.':schedules.view')
        ->name('ppb.dashboard');
    // A2 account and permission administration; all endpoints inherit HTTPS + auth middleware.
    Route::get('/accounts', [AccountController::class, 'index'])->middleware(PpbCan::class.':accounts.view')->name('ppb.accounts.index');
    Route::get('/accounts/create', [AccountController::class, 'create'])->middleware(PpbCan::class.':accounts.manage')->name('ppb.accounts.create');
    Route::post('/accounts', [AccountController::class, 'store'])->middleware([PpbCan::class.':accounts.manage', 'throttle:20,1'])->name('ppb.accounts.store');
    Route::get('/accounts/{account}/edit', [AccountController::class, 'edit'])->middleware(PpbCan::class.':accounts.manage')->name('ppb.accounts.edit');
    Route::put('/accounts/{account}', [AccountController::class, 'update'])->middleware([PpbCan::class.':accounts.manage', 'throttle:30,1'])->name('ppb.accounts.update');

    Route::get('/roles', [RoleController::class, 'index'])->middleware(PpbCan::class.':roles.manage')->name('ppb.roles.index');
    Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->middleware(PpbCan::class.':roles.manage')->name('ppb.roles.edit');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware([PpbCan::class.':roles.manage', 'throttle:20,1'])->name('ppb.roles.update');


    // A3 | 2026-11-03 | Operational modules: all actual data stays behind HTTPS + auth.
    Route::get('/analysts', [AnalystController::class, 'index'])->middleware(PpbCan::class.':schedules.view')->name('ppb.analysts.index');
    Route::get('/analysts/create', [AnalystController::class, 'create'])->middleware(PpbCan::class.':schedules.manage')->name('ppb.analysts.create');
    Route::post('/analysts', [AnalystController::class, 'store'])->middleware([PpbCan::class.':schedules.manage','throttle:20,1'])->name('ppb.analysts.store');
    Route::get('/analysts/{analyst}/edit', [AnalystController::class, 'edit'])->middleware(PpbCan::class.':schedules.manage')->name('ppb.analysts.edit');
    Route::put('/analysts/{analyst}', [AnalystController::class, 'update'])->middleware([PpbCan::class.':schedules.manage','throttle:20,1'])->name('ppb.analysts.update');

    Route::get('/games', [GameController::class, 'index'])->middleware(PpbCan::class.':schedules.view')->name('ppb.games.index');
    Route::get('/games/anchors', [GameController::class, 'anchors'])->middleware(PpbCan::class.':schedules.view')->name('ppb.games.anchors');
    Route::get('/games/create', [GameController::class, 'create'])->middleware(PpbCan::class.':schedules.manage')->name('ppb.games.create');
    Route::post('/games', [GameController::class, 'store'])->middleware([PpbCan::class.':schedules.manage','throttle:20,1'])->name('ppb.games.store');
    Route::get('/games/{game}', [GameController::class, 'show'])->middleware(PpbCan::class.':schedules.view')->name('ppb.games.show');
    Route::get('/games/{game}/edit', [GameController::class, 'edit'])->middleware(PpbCan::class.':schedules.manage')->name('ppb.games.edit');
    Route::put('/games/{game}', [GameController::class, 'update'])->middleware([PpbCan::class.':schedules.manage','throttle:20,1'])->name('ppb.games.update');
    // B1: Slot assignments are managed only through the validated game form.
    Route::delete('/games/{game}', [GameController::class, 'destroy'])->middleware([PpbCan::class.':schedules.manage','throttle:12,1'])->name('ppb.games.destroy');
    Route::post('/games/{game}/cancel', [GameController::class, 'cancel'])->middleware([PpbCan::class.':schedules.manage','throttle:12,1'])->name('ppb.games.cancel');
    Route::post('/assignments/{assignment}/substitute', [AssignmentController::class, 'substitute'])->middleware([PpbCan::class.':schedules.manage','throttle:20,1'])->name('ppb.assignments.substitute');
    Route::post('/assignments/{assignment}/status', [AssignmentController::class, 'status'])->middleware([PpbCan::class.':schedules.manage','throttle:20,1'])->name('ppb.assignments.status');

    // PPB W2 | 2026-11-06 | Authenticated editorial workflow.
    // The controller checks either schedules.manage or exact linked analyst/reviewer identity.
    Route::get('/manuscripts', [ManuscriptController::class, 'index'])->name('ppb.manuscripts.index');
    Route::get('/games/{game}/manuscripts', [ManuscriptController::class, 'show'])->name('ppb.manuscripts.game');
    Route::post('/manuscripts/assignments/{assignment}/save', [ManuscriptController::class, 'save'])
        ->middleware('throttle:30,1')->name('ppb.manuscripts.save');
    Route::post('/manuscripts/submissions/{submission}/review', [ManuscriptController::class, 'review'])
        ->whereNumber('submission')->middleware('throttle:30,1')->name('ppb.manuscripts.review');


    // Owner-only read-only preview. Never requires/migrates ppb_evaluation* tables.
    Route::get('/evaluations-preview', [\App\Http\Controllers\Ppb\EvaluationPreviewController::class, 'index'])
        ->middleware(PpbCan::class.':accounts.manage')->name('ppb.evalpreview.index');
    Route::get('/evaluations-preview/legacy/{id}', [\App\Http\Controllers\Ppb\EvaluationPreviewController::class, 'show'])
        ->whereNumber('id')->middleware(PpbCan::class.':accounts.manage')->name('ppb.evalpreview.show');

    Route::post('/logout', [AdminController::class, 'logout'])
        ->middleware('throttle:10,1')
        ->name('ppb.logout');
});
