<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FileLifecycleException;
use App\Files\FileLifecyclePolicy;
use App\Models\StoredFile;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class FileDownloadCapabilityController
{
    public function __invoke(
        Request $request,
        StoredFile $file,
        SecurityAuditRecorder $audit,
    ): JsonResponse {
        Gate::authorize('download', $file);

        if (! FileLifecyclePolicy::canIssueDownload($file->state) || ! is_string($file->clean_object_key) || $file->clean_object_key === '') {
            $audit->record(
                $request->user(),
                'file.download_capability',
                'denied',
                'stored_file',
                $file->id,
                ['reason' => 'not_available', 'state' => $file->state],
            );

            throw new FileLifecycleException(
                'FILE_NOT_AVAILABLE',
                Response::HTTP_CONFLICT,
                'The file is not available for download.',
            );
        }

        $expiresAt = now()->addSeconds(config('security.download_url_ttl_seconds'));
        $url = URL::temporarySignedRoute(
            'api.v1.files.content',
            $expiresAt,
            ['file' => $file->id],
        );

        $audit->record(
            $request->user(),
            'file.download_capability',
            'issued',
            'stored_file',
            $file->id,
            ['state' => $file->state, 'ttl_seconds' => config('security.download_url_ttl_seconds')],
        );

        return response()->json([
            'data' => [
                'url' => $url,
                'expires_at' => $expiresAt->toISOString(),
            ],
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
