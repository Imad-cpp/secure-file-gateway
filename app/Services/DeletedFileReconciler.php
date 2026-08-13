<?php

namespace App\Services;

use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DeletedFileReconciler
{
    public function reconcile(int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));
        $files = StoredFile::query()
            ->where('state', 'DELETED')
            ->where(function ($query): void {
                $query->whereNotNull('quarantine_object_key')
                    ->orWhereNotNull('clean_object_key');
            })
            ->oldest('updated_at')
            ->limit($limit)
            ->get();

        $cleaned = 0;

        foreach ($files as $file) {
            $changed = false;

            if (is_string($file->quarantine_object_key) && $file->quarantine_object_key !== '') {
                if ($this->deleteObject('quarantine', $file->quarantine_object_key)) {
                    $file->quarantine_object_key = null;
                    $changed = true;
                }
            }

            if (is_string($file->clean_object_key) && $file->clean_object_key !== '') {
                if ($this->deleteObject('clean', $file->clean_object_key)) {
                    $file->clean_object_key = null;
                    $changed = true;
                }
            }

            if ($changed) {
                $file->save();
            }

            if ($file->quarantine_object_key === null && $file->clean_object_key === null) {
                $cleaned++;
            }
        }

        return [
            'processed' => $files->count(),
            'cleaned' => $cleaned,
            'remaining' => $files->count() - $cleaned,
        ];
    }

    private function deleteObject(string $disk, string $key): bool
    {
        try {
            return Storage::disk($disk)->delete($key);
        } catch (Throwable) {
            return false;
        }
    }
}
