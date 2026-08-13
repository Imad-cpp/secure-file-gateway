<?php

use App\Contracts\ReadinessChecker;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'secure-file-gateway',
        'status' => 'scaffold',
        'api_version' => 'v1',
    ]);
});

Route::get('/health/live', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});

Route::get('/health/ready', function (ReadinessChecker $readiness) {
    if (! $readiness->isReady()) {
        return response()->json([
            'status' => 'not_ready',
        ], 503);
    }

    return response()->json([
        'status' => 'ready',
    ]);
});
