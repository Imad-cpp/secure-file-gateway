<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FileLifecycleException;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FileContentController
{
    public function __invoke(string $file): StreamedResponse
    {
        $storedFile = StoredFile::query()->findOrFail($file);

        if ($storedFile->state !== 'AVAILABLE' || ! is_string($storedFile->clean_object_key) || $storedFile->clean_object_key === '') {
            throw new FileLifecycleException(
                'FILE_NOT_AVAILABLE',
                Response::HTTP_CONFLICT,
                'The file is not available for download.',
            );
        }

        try {
            $stream = Storage::disk('clean')->readStream($storedFile->clean_object_key);
        } catch (Throwable $exception) {
            throw new FileLifecycleException(
                'DEPENDENCY_UNAVAILABLE',
                Response::HTTP_SERVICE_UNAVAILABLE,
                'Private file storage is unavailable.',
                $exception,
            );
        }

        if (! is_resource($stream)) {
            throw new FileLifecycleException(
                'DEPENDENCY_UNAVAILABLE',
                Response::HTTP_SERVICE_UNAVAILABLE,
                'Private file storage is unavailable.',
            );
        }

        $headers = [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $storedFile->detected_mime_type ?: 'application/octet-stream',
        ];

        if (is_int($storedFile->size_bytes) && $storedFile->size_bytes >= 0) {
            $headers['Content-Length'] = (string) $storedFile->size_bytes;
        }

        return response()->streamDownload(
            function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            },
            $this->safeDownloadName($storedFile->original_name),
            $headers,
        );
    }

    private function safeDownloadName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        return $name !== '' ? $name : 'download';
    }
}
