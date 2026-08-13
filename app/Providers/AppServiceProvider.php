<?php

namespace App\Providers;

use App\Contracts\MalwareScanner;
use App\Contracts\ReadinessChecker;
use App\Models\StoredFile;
use App\Policies\StoredFilePolicy;
use App\Scanning\ClamAvMalwareScanner;
use App\Services\InfrastructureReadinessChecker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MalwareScanner::class, ClamAvMalwareScanner::class);
        $this->app->bind(ReadinessChecker::class, InfrastructureReadinessChecker::class);
    }

    public function boot(): void
    {
        Gate::policy(StoredFile::class, StoredFilePolicy::class);

        RateLimiter::for('auth', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(config('security.auth_rate_limit_per_minute'))
                ->by($email.'|'.$request->ip());
        });

        RateLimiter::for('uploads', function (Request $request): Limit {
            $owner = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return Limit::perMinute(config('security.upload_rate_limit_per_minute'))
                ->by($owner.'|'.$request->ip());
        });

        RateLimiter::for('downloads', function (Request $request): Limit {
            $owner = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return Limit::perMinute(config('security.download_rate_limit_per_minute'))
                ->by($owner.'|'.$request->ip());
        });

        RateLimiter::for('download-content', function (Request $request): Limit {
            $file = (string) $request->route('file');

            return Limit::perMinute(config('security.download_content_rate_limit_per_minute'))
                ->by($file.'|'.$request->ip());
        });
    }
}
