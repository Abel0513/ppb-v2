<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2DeploymentCheckTest extends TestCase
{
    public function test_acceptance_page_never_opens_database_even_with_database_sessions(): void
    {
        $this->withoutExceptionHandling();
        config(['session.driver' => 'database', 'cache.default' => 'database']);
        DB::shouldReceive('connection')->never();
        $this->get('/v2/automation-check')
            ->assertOk()
            ->assertHeader('X-PPB-V2-Check', 'PPB_V2_AUTOMATION_PROOF_V1')
            ->assertCookieMissing(config('session.cookie'))
            ->assertSee('PPB_V2_AUTOMATION_PROOF_V1')
            ->assertSee('name="ppb-acceptance" content="lifecycle-receipt-verified"', false)
            ->assertSee('lang="zh-Hant"', false);
        $this->assertFalse($this->app->resolved('session.store'));
    }
}
