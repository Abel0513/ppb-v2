<?php

use App\Http\Controllers\Ppb\AttendanceDeleteController;
use App\Http\Controllers\Ppb\AdminController;
use App\Http\Controllers\Ppb\AccountController;
use App\Http\Controllers\Ppb\RoleController;
use App\Http\Controllers\Ppb\AnalystController;
use App\Http\Controllers\Ppb\GameController;
use App\Http\Controllers\Ppb\LegacyAdminController;
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
// Public, read-only 賽事排班總覽 (owner request 2026-11-26), same content as the old
// public eveonline_AdminSchedule_scheduleOverview page. No session, cookies or forms with CSRF.
Route::get('/schedule-overview', [GameController::class, 'index'])
    ->middleware(['throttle:60,1', 'cache.headers:public;max_age=60;etag'])
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    ])
    ->name('ppb.public.overview');
// Public, read-only 分析文排班 (owner request 2026-11-27), old analysisScheduleOverview equivalent.
Route::get('/analysis-schedule', [LegacyAdminController::class, 'articles'])
    ->middleware(['throttle:60,1', 'cache.headers:public;max_age=60;etag'])
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    ])
    ->name('ppb.public.articles');
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
    Route::middleware(PpbCan::class.':schedules.view')->group(function () {
        Route::get('/games/readonly', [GameController::class, 'index'])->name('ppb.games.readonly');
        Route::get('/schedule/overview', [GameController::class, 'index'])->name('ppb.schedule.overview');
        Route::get('/schedule/articles', [LegacyAdminController::class, 'articles'])->name('ppb.schedule.articles');
        Route::get('/schedule/videos', [LegacyAdminController::class, 'videos'])->name('ppb.schedule.videos');
        Route::get('/schedule/attendance', [LegacyAdminController::class, 'attendance'])->name('ppb.schedule.attendance');
        Route::get('/analyst-list', [LegacyAdminController::class, 'report'])->defaults('kind', 'analyst-list')->name('ppb.analyst-list');
        Route::get('/performance', [LegacyAdminController::class, 'report'])->defaults('kind', 'performance')->name('ppb.performance');
        Route::get('/performance/summary/{type}/{id}', [LegacyAdminController::class, 'summary'])
            ->whereIn('type', ['legacy', 'v2'])->whereNumber('id')->defaults('kind', 'schedule')->name('ppb.performance.summary');
        Route::get('/csda/users', [LegacyAdminController::class, 'csda'])->name('ppb.csda.users');
    });
    Route::post('/schedule/attendance/delete', AttendanceDeleteController::class)
        ->middleware([PpbCan::class.':evaluations.view', PpbCan::class.':evaluations.manage', 'throttle:30,1'])
        ->name('ppb.schedule.attendance.delete');

    Route::prefix('rating')->middleware(PpbCan::class.':evaluations.view')->group(function () {
        foreach (['index' => 'rating-salary', 'salary' => 'salary', 'change' => 'rating'] as $path => $kind) {
            Route::get('/'.$path, [LegacyAdminController::class, 'report'])->defaults('kind', $kind)->name('ppb.rating.'.$path);
        }
        Route::get('/summary/{type}/{id}', [LegacyAdminController::class, 'summary'])
            ->whereIn('type', ['legacy', 'v2'])->whereNumber('id')->defaults('kind', 'rating')->name('ppb.rating.summary');
    });
    Route::get('/games/unsettled-count', [GameController::class, 'unsettledCount'])
        ->middleware(PpbCan::class.':schedules.view')->name('ppb.games.unsettled-count');
    Route::get('/games/anchors', [GameController::class, 'anchors'])->middleware(PpbCan::class.':schedules.view')->name('ppb.games.anchors');
    Route::get('/games/{game}/assignments/{assignment}/rating', [\App\Http\Controllers\Ppb\EvaluationController::class, 'scheduleRating'])
        ->middleware(PpbCan::class.':schedules.view')->name('ppb.games.assignment-rating');
    Route::get('/schedule/videos/receipt/{type}/{id}', [\App\Http\Controllers\Ppb\EvaluationController::class, 'videoReceipt'])
        ->whereIn('type', ['legacy', 'v2'])->whereNumber('id')
        ->middleware([PpbCan::class.':schedules.view', PpbCan::class.':evaluations.view', PpbCan::class.':evaluations.manage'])
        ->name('ppb.schedule.videos.receipt');
    Route::get('/games/form-options', [GameController::class, 'formOptions'])->middleware(PpbCan::class.':schedules.manage')->name('ppb.games.form-options');
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
    // Backend management only; account_id never delegates analyst LINE/LIFF authority.
    Route::get('/manuscripts', [ManuscriptController::class, 'index'])->middleware(PpbCan::class.':schedules.manage')->name('ppb.manuscripts.index');
    Route::get('/games/{game}/manuscripts', [ManuscriptController::class, 'show'])->middleware(PpbCan::class.':schedules.manage')->name('ppb.manuscripts.game');
    Route::post('/manuscripts/assignments/{assignment}/save', [ManuscriptController::class, 'save'])
        ->middleware([PpbCan::class.':schedules.manage','throttle:30,1'])->name('ppb.manuscripts.save');
    Route::post('/manuscripts/submissions/{submission}/review', [ManuscriptController::class, 'review'])
        ->whereNumber('submission')->middleware([PpbCan::class.':schedules.manage','throttle:30,1'])->name('ppb.manuscripts.review');

    // Evaluations remain an administrative workflow, not analyst authentication.
    Route::get('/evaluations', [\App\Http\Controllers\Ppb\EvaluationController::class,'index'])->middleware(PpbCan::class.':evaluations.view')->name('ppb.evaluations.index');
    Route::get('/evaluations/{type}/{id}', [\App\Http\Controllers\Ppb\EvaluationController::class,'show'])->whereIn('type',['legacy','v2'])->whereNumber('id')->middleware(PpbCan::class.':evaluations.view')->name('ppb.evaluations.show');
    Route::post('/evaluations/{type}/{id}', [\App\Http\Controllers\Ppb\EvaluationController::class,'save'])->whereIn('type',['legacy','v2'])->whereNumber('id')->middleware([PpbCan::class.':evaluations.manage','throttle:30,1'])->name('ppb.evaluations.save');

    // Owner-only read-only preview. Never requires/migrates ppb_evaluation* tables.
    Route::get('/evaluations-preview', [\App\Http\Controllers\Ppb\EvaluationPreviewController::class, 'index'])
        ->middleware(PpbCan::class.':accounts.manage')->name('ppb.evalpreview.index');
    Route::get('/evaluations-preview/health', [\App\Http\Controllers\Ppb\EvaluationPreviewController::class, 'health'])
        ->middleware(PpbCan::class.':accounts.manage')->name('ppb.evalpreview.health');
    Route::get('/evaluations-preview/legacy/{id}', [\App\Http\Controllers\Ppb\EvaluationPreviewController::class, 'show'])
        ->whereNumber('id')->middleware(PpbCan::class.':accounts.manage')->name('ppb.evalpreview.show');

    Route::post('/logout', [AdminController::class, 'logout'])
        ->middleware('throttle:10,1')
        ->name('ppb.logout');
});

// Compatibility applies only to this Laravel V2 host; old server routes are unchanged.
Route::middleware([PpbRequireSecureHost::class,'auth:ppb',PpbEnsureActive::class,PpbCan::class.':evaluations.view'])->group(function(){
    foreach(['/Admin/AnalystRating/index','/Admin/AnalystRating/salary','/Admin/AnalystRating/rating','/Admin/AnalystPerformance/index'] as $legacyEntry){
        foreach([$legacyEntry,$legacyEntry.'.html'] as $entry)Route::get($entry,fn(\Illuminate\Http\Request $request)=>redirect()->route('ppb.evaluations.index',['month'=>$request->query('month')]));
    }
});
