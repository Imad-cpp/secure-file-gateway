<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_strong_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Imad Test',
            'email' => 'IMAD@example.test',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.email', 'imad@example.test')
            ->assertJsonMissingPath('data.password');

        $user = User::query()->where('email', 'imad@example.test')->firstOrFail();

        $this->assertTrue(Hash::check('StrongPass123!', $user->password));
    }

    public function test_registration_rejects_weak_password(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Weak Password',
            'email' => 'weak@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_login_issues_bearer_token_and_me_requires_it(): void
    {
        $user = User::query()->create([
            'name' => 'Token User',
            'email' => 'token@example.test',
            'password' => 'StrongPass123!',
        ]);

        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'token@example.test',
            'password' => 'StrongPass123!',
            'device_name' => 'phpunit',
        ])->assertOk();

        $token = $login->json('data.token');

        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $login->assertJsonPath('data.token_type', 'Bearer');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_invalid_credentials_are_generic_and_rate_limited(): void
    {
        User::query()->create([
            'name' => 'Rate Limited User',
            'email' => 'rate-limit@example.test',
            'password' => 'StrongPass123!',
        ]);

        for ($attempt = 1; $attempt <= config('security.auth_rate_limit_per_minute'); $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'rate-limit@example.test',
                'password' => 'WrongPass123!',
            ])
                ->assertUnauthorized()
                ->assertJsonPath('error.code', 'UNAUTHENTICATED');
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'rate-limit@example.test',
            'password' => 'WrongPass123!',
        ])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::query()->create([
            'name' => 'Logout User',
            'email' => 'logout@example.test',
            'password' => 'StrongPass123!',
        ]);

        $token = $user->createToken('phpunit')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }
}
