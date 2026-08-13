<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\SecurityAuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_id_is_server_generated_for_success_and_error_responses(): void
    {
        $success = $this->withHeader('X-Request-ID', 'attacker-controlled')
            ->get('/health/live')
            ->assertOk();

        $successRequestId = (string) $success->headers->get('X-Request-ID');

        $this->assertTrue(Str::isUuid($successRequestId));
        $this->assertNotSame('attacker-controlled', $successRequestId);

        $error = $this->getJson('/api/v1/files')
            ->assertUnauthorized();

        $errorRequestId = (string) $error->headers->get('X-Request-ID');

        $this->assertTrue(Str::isUuid($errorRequestId));
        $this->assertNotSame($successRequestId, $errorRequestId);
    }

    public function test_audit_metadata_sanitizer_drops_sensitive_keys_recursively(): void
    {
        $user = User::query()->create([
            'name' => 'Audit Owner',
            'email' => 'audit-owner@example.test',
            'password' => 'StrongPass123!',
        ]);

        $recorded = app(SecurityAuditRecorder::class)->record(
            $user,
            'file.download_capability',
            'issued',
            'stored_file',
            (string) Str::uuid(),
            [
                'state' => 'AVAILABLE',
                'ttl_seconds' => 300,
                'token' => 'must-not-persist',
                'signed_url' => 'https://example.test/private?signature=secret',
                'clean_object_key' => 'private/object',
                'nested' => [
                    'Authorization' => 'Bearer secret',
                    'reason' => 'safe',
                ],
            ],
        );

        $this->assertTrue($recorded);

        $event = AuditEvent::query()->sole();

        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame('file.download_capability', $event->action);
        $this->assertSame('issued', $event->outcome);
        $this->assertSame('AVAILABLE', $event->metadata['state']);
        $this->assertSame(300, $event->metadata['ttl_seconds']);
        $this->assertSame(['reason' => 'safe'], $event->metadata['nested']);
        $this->assertArrayNotHasKey('token', $event->metadata);
        $this->assertArrayNotHasKey('signed_url', $event->metadata);
        $this->assertArrayNotHasKey('clean_object_key', $event->metadata);
    }

    public function test_failed_login_audit_does_not_store_submitted_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.test',
            'password' => 'DefinitelySecret123!',
        ])->assertUnauthorized();

        $event = AuditEvent::query()->sole();

        $this->assertSame('auth.login', $event->action);
        $this->assertSame('denied', $event->outcome);
        $this->assertNull($event->actor_id);
        $this->assertSame(['reason' => 'invalid_credentials'], $event->metadata);
        $this->assertSame($response->headers->get('X-Request-ID'), $event->request_id);
    }
}
