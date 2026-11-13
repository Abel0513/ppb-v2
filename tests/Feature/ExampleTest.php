<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_root_preserves_v2_admin_redirect(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('https://v2.csdatw.xyz/admin');
    }
}
