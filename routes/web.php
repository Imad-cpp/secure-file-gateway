<?php

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

Route::get('/health/ready', function () {
    return response()->json([
        'status' => 'not_ready',
        'reason' => 'dependency probes are introduced with the integration layer',
    ], 503);
});
