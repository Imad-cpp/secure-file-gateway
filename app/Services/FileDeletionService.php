<?php

namespace App\Services;

use App\Exceptions\FileLifecycleException;
use App\Files\FileLifecyclePolicy;
use App\Models\StoredFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FileDeletionService
{
    public function delete(StoredFile $file): void
    {
        $keys = DB::transaction(function () use ($file): array {
            $locked = StoredFile::query()
                ->whereKey($file->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->state !== FileLifecyclePolicy::DELETED) {
                $locked->forceFill([
                    'state' => FileLifecyclePolicy::DELETED,
                    'deleted_sha256' => $locked->deleted_sha256 ?? $locked->sha256,
                    'sha256' => null,
                    'deleted_at' => now(),
                ])->save();
            }

            return [
                'quarantine' => $locked->quarantine_object_key,
                'clean' => $locked->clean_object_key,
            ];
        });

        foreach ($keys as $disk => $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            try {
                if (Storage::disk($disk)->delete($key) !== true) {
                    throw new FileLifecycleException(
                        'DEPENDENCY_UNAVAILABLE',
                        Response::HTTP_SERVICE_UNAVAILABLE,
                        'File deletion is pending because private storage is unavailable.',
                    );
                }
            } catch (FileLifecycleException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new FileLifecycleException(
                    'DEPENDENCY_UNAVAILABLE',
                    Response::HTTP_SERVICE_UNAVAILABLE,
                    'File deletion is pending because private storage is unavailable.',
                    $exception,
                );
            }
        }

        try {
            StoredFile::query()
                ->whereKey($file->id)
                ->where('state', FileLifecyclePolicy::DELETED)
                ->update([
                    'quarantine_object_key' => null,
                    'clean_object_key' => null,
                ]);
        } catch (Throwable $exception) {
            throw new FileLifecycleException(
                'DEPENDENCY_UNAVAILABLE',
                Response::HTTP_SERVICE_UNAVAILABLE,
                'File deletion is complete in storage but metadata cleanup is pending.',
                $exception,
            );
        }
    }
}
