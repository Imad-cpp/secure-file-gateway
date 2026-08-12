<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreFileRequest;
use App\Services\FileIngestionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class FileIngestionController
{
    public function __invoke(StoreFileRequest $request, FileIngestionService $ingestion): JsonResponse
    {
        $storedFile = $ingestion->ingest(
            $request->user(),
            $request->file('file'),
        );

        return response()->json([
            'data' => [
                'id' => $storedFile->id,
                'original_name' => $storedFile->original_name,
                'detected_mime_type' => $storedFile->detected_mime_type,
                'size_bytes' => $storedFile->size_bytes,
                'sha256' => $storedFile->sha256,
                'state' => $storedFile->state,
                'created_at' => $storedFile->created_at?->toISOString(),
            ],
        ], Response::HTTP_ACCEPTED);
    }
}
