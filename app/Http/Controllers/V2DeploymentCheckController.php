<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

final class V2DeploymentCheckController extends Controller
{
    public function __invoke(): Response
    {
        return new Response(view('ppb.deployment-check'), 200, [
            'Cache-Control' => 'no-store, private',
            'X-PPB-V2-Check' => 'PPB_V2_AUTOMATION_PROOF_V1',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
