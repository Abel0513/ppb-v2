<?php

use Illuminate\Support\Facades\Route;

// PPB_V2_ROUTE_GATE_20261104
Route::redirect('/', 'https://v2.csdatw.xyz/admin', 302);

// PPB_A1_ROUTES | 2026-11-03
require __DIR__.'/ppb_admin.php';

// Stateless V2 deployment acceptance: no auth, sessions, DB, WP or external calls.
Route::get('/v2-automation-check', \App\Http\Controllers\V2DeploymentCheckController::class)
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    ])
    ->name('v2.automation-check');
