<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FileLifecycleException;
use App\Models\StoredFile;
use App\Services\FileDeletionService;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class FileDeletionController
{
    public function __invoke(
        Request $request,
        StoredFile $file,
        FileDeletionService $deletion,
        SecurityAuditRecorder $audit,
    ): Response {
        Gate::authorize('delete', $file);

        try {
            $deletion->delete($file);
        } catch (FileLifecycleException $exception) {
            $file->refresh();

            $audit->record(
                $request->user(),
                'file.delete',
                'partial_failure',
                'stored_file',
                $file->id,
                ['state' => $file->state, 'error_code' => $exception->apiCode],
            );

            throw $exception;
        }

        $file->refresh();

        $audit->record(
            $request->user(),
            'file.delete',
            'success',
            'stored_file',
            $file->id,
            ['state' => $file->state],
        );

        return response()->noContent();
    }
}
