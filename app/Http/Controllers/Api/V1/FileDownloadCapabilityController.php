<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FileLifecycleException;
use App\Models\StoredFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class FileDownloadCapabilityController
{
    public function __invoke(StoredFile $file): JsonResponse
    {
        Gate::authorize('download', $file);

        if ($file->state !== 'AVAILABLE' || ! is_string($file->clean_object_key) || $file->clean_object_key === '') {
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
