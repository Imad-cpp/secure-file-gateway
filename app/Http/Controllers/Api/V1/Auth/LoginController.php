<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Models\User;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class LoginController
{
    public function __invoke(LoginRequest $request, SecurityAuditRecorder $audit): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->string('email')->toString())
            ->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            $audit->record(null, 'auth.login', 'denied', metadata: [
                'reason' => 'invalid_credentials',
            ]);

            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'The provided credentials are invalid.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill([
                'password' => Hash::make($request->string('password')->toString()),
            ])->save();
        }

        $expiresAt = now()->addMinutes(config('security.api_token_ttl_minutes'));
        $token = $user->createToken(
            $request->string('device_name')->toString() ?: 'api-client',
            ['*'],
            $expiresAt,
        );

        $audit->record($user, 'auth.login', 'success', 'user', $user->id);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toISOString(),
            ],
        ]);
    }
}
