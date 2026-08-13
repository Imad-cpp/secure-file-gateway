<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Models\User;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RegisterController
{
    public function __invoke(RegisterRequest $request, SecurityAuditRecorder $audit): JsonResponse
    {
        $user = User::query()->create($request->validated());

        $audit->record($user, 'auth.register', 'success', 'user', $user->id);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toISOString(),
            ],
        ], Response::HTTP_CREATED);
    }
}
