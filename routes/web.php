<?php

use Illuminate\Support\Facades\Route;

// PPB_V2_ROUTE_GATE_20261104
Route::redirect('/', 'https://v2.csdatw.xyz/admin', 302);

// PPB_A1_ROUTES | 2026-11-03
require __DIR__.'/ppb_admin.php';
