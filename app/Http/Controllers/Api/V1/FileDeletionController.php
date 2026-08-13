<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StoredFile;
use App\Services\FileDeletionService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class FileDeletionController
{
    public function __invoke(StoredFile $file, FileDeletionService $deletion): Response
    {
        Gate::authorize('delete', $file);
        $deletion->delete($file);

        return response()->noContent();
    }
}
