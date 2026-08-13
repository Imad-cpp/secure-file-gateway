<?php

namespace App\Services;

use App\Contracts\ReadinessChecker;
use App\Infrastructure\ClamAvHealthProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

class InfrastructureReadinessChecker implements ReadinessChecker
{
    public function __construct(
        private readonly ClamAvHealthProbe $scanner,
    ) {}

    public function isReady(): bool
    {
        try {
            DB::select('select 1');
            Redis::connection()->ping();
            Storage::disk('quarantine')->exists('__secure_file_gateway_readiness_probe__');
            Storage::disk('clean')->exists('__secure_file_gateway_readiness_probe__');

            return $this->scanner->check();
        } catch (Throwable) {
            return false;
        }
    }
}
