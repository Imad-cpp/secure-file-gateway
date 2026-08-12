<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Application services are registered as implementation modules arrive.
    }

    public function boot(): void
    {
        // Global application bootstrapping belongs here when required.
    }
}
