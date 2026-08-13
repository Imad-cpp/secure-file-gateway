<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Services\SecurityAuditRecorder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogoutController
{
    public function __invoke(Request $request, SecurityAuditRecorder $audit): Response
    {
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        $audit->record($user, 'auth.logout', 'success', 'user', $user->id);

        return response()->noContent();
    }
}
