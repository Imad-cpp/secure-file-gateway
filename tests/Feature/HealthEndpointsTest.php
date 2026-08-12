<?php

namespace Tests\Feature;

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

    public function test_readiness_fails_closed_until_dependency_probes_exist(): void
    {
        $this->get('/health/ready')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'not_ready',
                'reason' => 'dependency probes are introduced with the integration layer',
            ]);
    }
}
