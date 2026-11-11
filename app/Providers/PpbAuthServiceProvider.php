<?php

namespace App\Providers;

use App\Models\PpbAccount;
use Illuminate\Support\ServiceProvider;

/** New, isolated session guard: does not replace Laravel's default users guard. */
class PpbAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config()->set('auth.providers.ppb_accounts', [
            'driver' => 'eloquent',
            'model' => PpbAccount::class,
        ]);
        config()->set('auth.guards.ppb', [
            'driver' => 'session',
            'provider' => 'ppb_accounts',
        ]);
    }
}
