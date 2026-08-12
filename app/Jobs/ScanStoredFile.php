<?php

namespace App\Jobs;

use App\Contracts\MalwareScanner;
use App\Models\StoredFile;
use App\Scanning\MalwareScanResult;
use App\Scanning\MalwareScanVerdict;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ScanStoredFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;
    public bool $failOnTimeout = true;

    public function __construct(public readonly string $fileId)
    {
        $this->onQueue('scans');
    }

    public function backoff(): array
    {
        return [5, 30];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('scan:'.$this->fileId))->releaseAfter(5)->expireAfter(60)];
    }

    public function handle(MalwareScanner $scanner): void
    {
        $file = StoredFile::query()->find($this->fileId);

        if (! $file || in_array($file->state, ['AVAILABLE', 'REJECTED', 'SCAN_FAILED', 'DELETED'], true)) {
            return;
        }

        if ($file->state === 'QUARANTINED') {
            StoredFile::query()->whereKey($file->id)->where('state', 'QUARANTINED')->update(['state' => 'SCANNING']);
            $file->refresh();
        }

        if ($file->state !== 'SCANNING') {
            return;
        }

        $result = $scanner->scan($file);

        match ($result->verdict) {
            MalwareScanVerdict::CLEAN => $this->promoteCleanFile($file, $result),
            MalwareScanVerdict::UNSAFE => $this->rejectUnsafeFile($file, $result),
        };
    }

    public function failed(?Throwable $exception): void
    {
        StoredFile::query()->whereKey($this->fileId)->whereIn('state', ['QUARANTINED', 'SCANNING'])->update([
            'state' => 'SCAN_FAILED',
            'scan_engine' => 'clamav',
            'scan_signature' => null,
            'scan_completed_at' => now(),
        ]);
    }

    private function promoteCleanFile(StoredFile $file, MalwareScanResult $result): void
    {
        $quarantineKey = $this->requireQuarantineKey($file);
        $cleanKey = $file->owner_id.'/'.$file->id;
        $input = Storage::disk('quarantine')->readStream($quarantineKey);

        if (! is_resource($input)) {
            throw new RuntimeException('The quarantine object could not be opened for clean promotion.');
        }

        try {
            $written = Storage::disk('clean')->put($cleanKey, $input, ['visibility' => 'private']);
        } finally {
            fclose($input);
        }

        if ($written !== true) {
            throw new RuntimeException('The clean object could not be persisted.');
        }

        try {
            $updated = StoredFile::query()->whereKey($file->id)->where('state', 'SCANNING')->update([
                'state' => 'AVAILABLE',
                'clean_object_key' => $cleanKey,
                'scan_engine' => 'clamav',
                'scan_signature' => $result->signature,
                'scan_completed_at' => now(),
            ]);

            if ($updated !== 1) {
                throw new RuntimeException('The file lifecycle changed during clean promotion.');
            }
        } catch (Throwable $exception) {
            $this->bestEffortDelete('clean', $cleanKey);
            throw $exception;
        }

        if ($this->bestEffortDelete('quarantine', $quarantineKey)) {
            StoredFile::query()->whereKey($file->id)->update(['quarantine_object_key' => null]);
        }
    }

    private function rejectUnsafeFile(StoredFile $file, MalwareScanResult $result): void
    {
        $quarantineKey = $this->requireQuarantineKey($file);
        $updated = StoredFile::query()->whereKey($file->id)->where('state', 'SCANNING')->update([
            'state' => 'REJECTED',
            'scan_engine' => 'clamav',
            'scan_signature' => $result->signature,
            'scan_completed_at' => now(),
        ]);

        if ($updated !== 1) {
            throw new RuntimeException('The file lifecycle changed during unsafe rejection.');
        }

        if ($this->bestEffortDelete('quarantine', $quarantineKey)) {
            StoredFile::query()->whereKey($file->id)->update(['quarantine_object_key' => null]);
        }
    }

    private function requireQuarantineKey(StoredFile $file): string
    {
        $key = $file->quarantine_object_key;

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('The file has no quarantine object to process.');
        }

        return $key;
    }

    private function bestEffortDelete(string $disk, string $key): bool
    {
        try {
            return Storage::disk($disk)->delete($key);
        } catch (Throwable) {
            return false;
        }
    }
}
