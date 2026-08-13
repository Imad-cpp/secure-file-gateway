<?php

namespace App\Services;

use App\Exceptions\IngestionException;
use App\Jobs\ScanStoredFile;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FileIngestionService
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
    ) {}

    public function ingest(User $owner, UploadedFile $upload): StoredFile
    {
        $size = $upload->getSize();

        if (! is_int($size) || $size < 0) {
            throw new IngestionException(
                'VALIDATION_FAILED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'The uploaded file could not be read.',
            );
        }

        if ($size > config('file_ingestion.max_bytes')) {
            throw new IngestionException(
                'FILE_TOO_LARGE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'The uploaded file exceeds the 10 MiB limit.',
            );
        }

        $originalName = $this->safeOriginalName($upload->getClientOriginalName());
        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedTypes = config('file_ingestion.allowed_types');

        if ($extension === '' || ! array_key_exists($extension, $allowedTypes)) {
            throw new IngestionException(
                'FILE_TYPE_NOT_ALLOWED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'The uploaded file extension is not allowed.',
            );
        }

        $realPath = $upload->getRealPath();

        if (! is_string($realPath) || $realPath === '') {
            throw new IngestionException(
                'VALIDATION_FAILED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'The uploaded file could not be read.',
            );
        }

        $fileId = (string) Str::uuid();
        $objectKey = $this->writeToQuarantine($owner, $upload, $fileId);
        $cleanupQuarantine = true;

        try {
            $detectedMimeType = $this->detectMimeType($realPath);

            if (! in_array($detectedMimeType, $allowedTypes[$extension], true)) {
                throw new IngestionException(
                    'FILE_TYPE_MISMATCH',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'The uploaded file content does not match its allowed extension.',
                );
            }

            $sha256 = hash_file('sha256', $realPath);

            if (! is_string($sha256) || strlen($sha256) !== 64) {
                throw new IngestionException(
                    'DEPENDENCY_UNAVAILABLE',
                    Response::HTTP_SERVICE_UNAVAILABLE,
                    'A required file-processing dependency is unavailable.',
                );
            }

            if (StoredFile::query()
                ->where('owner_id', $owner->id)
                ->where('sha256', $sha256)
                ->exists()) {
                throw $this->duplicateException();
            }

            $storedFile = new StoredFile([
                'original_name' => $originalName,
                'detected_mime_type' => $detectedMimeType,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'quarantine_object_key' => $objectKey,
                'clean_object_key' => null,
                'state' => 'QUARANTINED',
            ]);
            $storedFile->id = $fileId;

            try {
                $owner->storedFiles()->save($storedFile);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    throw $this->duplicateException($exception);
                }

                throw new IngestionException(
                    'DEPENDENCY_UNAVAILABLE',
                    Response::HTTP_SERVICE_UNAVAILABLE,
                    'The file metadata store is unavailable.',
                    $exception,
                );
            }

            $storedFile = $storedFile->refresh();

            try {
                $this->dispatcher->dispatch(new ScanStoredFile($storedFile->id));
            } catch (Throwable $exception) {
                $metadataRemoved = $this->removeMetadataAfterDispatchFailure($storedFile);

                if (! $metadataRemoved) {
                    $cleanupQuarantine = false;
                    $this->markScanFailedAfterDispatchFailure($storedFile);
                }

                throw new IngestionException(
                    'DEPENDENCY_UNAVAILABLE',
                    Response::HTTP_SERVICE_UNAVAILABLE,
                    'The malware scan queue is unavailable.',
                    $exception,
                );
            }

            return $storedFile;
        } catch (Throwable $exception) {
            if ($cleanupQuarantine) {
                $this->cleanupQuarantine($objectKey);
            }

            throw $exception;
        }
    }

    private function writeToQuarantine(User $owner, UploadedFile $upload, string $fileId): string
    {
        try {
            $objectKey = Storage::disk('quarantine')->putFileAs(
                $owner->id,
                $upload,
                $fileId,
                ['visibility' => 'private'],
            );
        } catch (FilesystemException $exception) {
            throw new IngestionException(
                'DEPENDENCY_UNAVAILABLE',
                Response::HTTP_SERVICE_UNAVAILABLE,
                'Private quarantine storage is unavailable.',
                $exception,
            );
        }

        if (! is_string($objectKey) || $objectKey === '') {
            throw new IngestionException(
                'DEPENDENCY_UNAVAILABLE',
                Response::HTTP_SERVICE_UNAVAILABLE,
                'Private quarantine storage is unavailable.',
            );
        }

        return $objectKey;
    }

    private function detectMimeType(string $realPath): string
    {
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $detector->file($realPath);

        if (! is_string($mimeType) || $mimeType === '') {
            throw new IngestionException(
                'FILE_TYPE_MISMATCH',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'The uploaded file type could not be verified.',
            );
        }

        return $mimeType;
    }

    private function safeOriginalName(string $clientName): string
    {
        $name = basename(str_replace('\\', '/', $clientName));
        $name = trim($name);

        if ($name === '') {
            $name = 'upload';
        }

        return mb_substr($name, 0, 255);
    }

    private function cleanupQuarantine(string $objectKey): void
    {
        try {
            Storage::disk('quarantine')->delete($objectKey);
        } catch (Throwable) {
            // Orphan reconciliation belongs to the hardening layer.
        }
    }

    private function removeMetadataAfterDispatchFailure(StoredFile $storedFile): bool
    {
        try {
            return (bool) $storedFile->delete();
        } catch (Throwable) {
            return false;
        }
    }

    private function markScanFailedAfterDispatchFailure(StoredFile $storedFile): void
    {
        try {
            StoredFile::query()
                ->whereKey($storedFile->id)
                ->where('state', 'QUARANTINED')
                ->update([
                    'state' => 'SCAN_FAILED',
                    'scan_engine' => 'clamav',
                    'scan_signature' => null,
                    'scan_completed_at' => now(),
                ]);
        } catch (Throwable) {
            // Preserve the private quarantine object when metadata compensation cannot complete.
        }
    }

    private function duplicateException(?Throwable $previous = null): IngestionException
    {
        return new IngestionException(
            'DUPLICATE_FILE',
            Response::HTTP_CONFLICT,
            'An identical file already exists for this owner.',
            $previous,
        );
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
