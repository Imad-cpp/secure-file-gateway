<?php

namespace Tests\Feature;

use App\Contracts\ReadinessChecker;
use Tests\TestCase;

class HealthEndpointsTest extends TestCase
{
    public function test_root_exposes_only_basic_service_metadata(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertExactJson([
                'service' => 'secure-file-gateway',
                'status' => 'scaffold',
                'api_version' => 'v1',
            ]);
    }

    public function test_liveness_reports_that_the_application_booted(): void
    {
        $this->get('/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_fails_closed_without_exposing_dependency_details(): void
    {
        $this->app->bind(ReadinessChecker::class, fn () => new class implements ReadinessChecker
        {
            public function isReady(): bool
            {
                return false;
            }
        });

        $this->get('/health/ready')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'not_ready']);
    }

    public function test_readiness_reports_ready_only_when_checker_passes(): void
    {
        $this->app->bind(ReadinessChecker::class, fn () => new class implements ReadinessChecker
        {
            public function isReady(): bool
            {
                return true;
            }
        });

        $this->get('/health/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'ready']);
    }
}
