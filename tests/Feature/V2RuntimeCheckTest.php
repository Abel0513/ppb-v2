<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2RuntimeCheckTest extends TestCase
{
    protected function tearDown(): void { putenv('PPB_V2_RELEASE_COMMIT'); parent::tearDown(); }
    public function test_actual_connection_identity_contract_without_a_test_database(): void
    {
        putenv('PPB_V2_RELEASE_COMMIT='.str_repeat('a',40));
        DB::shouldReceive('transactionLevel')->twice()->andReturn(0);
        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('selectOne')->once()->with('SELECT DATABASE() AS db, CURRENT_USER() AS actor')
            ->andReturn((object)['db'=>'v2_fixture','actor'=>'v2_worker@localhost']);
        DB::shouldReceive('rollBack')->once();
        $this->artisan('ppb:runtime-check')->expectsOutputToContain('"transaction_rolled_back":true')->assertExitCode(0);
    }
    public function test_failed_query_is_rolled_back_and_does_not_report_pass(): void
    {
        putenv('PPB_V2_RELEASE_COMMIT='.str_repeat('a',40));
        DB::shouldReceive('transactionLevel')->once()->andReturn(0);
        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('selectOne')->once()->andThrow(new \RuntimeException('private detail'));
        DB::shouldReceive('rollBack')->once();
        $this->artisan('ppb:runtime-check')->expectsOutputToContain('V2_DATABASE_RUNTIME_NOT_VERIFIED')->assertExitCode(1);
    }
}
