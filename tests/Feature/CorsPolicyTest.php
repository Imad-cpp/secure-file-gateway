<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsPolicyTest extends TestCase
{
    public function test_untrusted_origin_is_not_granted_cross_origin_access_by_default(): void
    {
        $response = $this->withHeader('Origin', 'https://untrusted.example')
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }
}
