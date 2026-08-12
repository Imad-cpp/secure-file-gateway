<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StoredFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FileMetadataController
{
    public function index(Request $request): JsonResponse
    {
        $files = $request->user()
            ->storedFiles()
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $files->getCollection()
                ->map(fn (StoredFile $file): array => $this->serialize($file))
                ->values(),
            'meta' => [
                'current_page' => $files->currentPage(),
                'per_page' => $files->perPage(),
                'total' => $files->total(),
                'last_page' => $files->lastPage(),
            ],
        ]);
    }

    public function show(StoredFile $file): JsonResponse
    {
        Gate::authorize('view', $file);

        return response()->json([
            'data' => $this->serialize($file),
        ]);
    }

    private function serialize(StoredFile $file): array
    {
        return [
            'id' => $file->id,
            'original_name' => $file->original_name,
            'detected_mime_type' => $file->detected_mime_type,
            'size_bytes' => $file->size_bytes,
            'sha256' => $file->sha256,
            'state' => $file->state,
            'created_at' => $file->created_at?->toISOString(),
        ];
    }
}
