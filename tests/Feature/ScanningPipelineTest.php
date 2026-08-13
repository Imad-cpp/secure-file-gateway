<?php

namespace Tests\Feature;

use App\Contracts\MalwareScanner;
use App\Exceptions\ScannerException;
use App\Jobs\ScanStoredFile;
use App\Models\StoredFile;
use App\Models\User;
use App\Scanning\MalwareScanResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScanningPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('quarantine');
        Storage::fake('clean');
    }

    public function test_clean_scan_promotes_private_object_and_marks_file_available(): void
    {
        $file = $this->quarantinedFile('clean fixture');
        $job = new ScanStoredFile($file->id);

        $job->handle($this->scannerReturning(MalwareScanResult::clean()));

        $file->refresh();

        $this->assertSame('AVAILABLE', $file->state);
        $this->assertSame('clamav', $file->scan_engine);
        $this->assertNull($file->scan_signature);
        $this->assertNotNull($file->scan_completed_at);
        $this->assertNull($file->quarantine_object_key);
        $this->assertSame($file->owner_id.'/'.$file->id, $file->clean_object_key);
        Storage::disk('clean')->assertExists($file->clean_object_key);
        $this->assertSame([], Storage::disk('quarantine')->allFiles());
    }

    public function test_unsafe_scan_is_rejected_without_clean_storage_promotion(): void
    {
        $file = $this->quarantinedFile('unsafe fixture');
        $job = new ScanStoredFile($file->id);

        $job->handle($this->scannerReturning(MalwareScanResult::unsafe('Eicar-Test-Signature')));

        $file->refresh();

        $this->assertSame('REJECTED', $file->state);
        $this->assertSame('clamav', $file->scan_engine);
        $this->assertSame('Eicar-Test-Signature', $file->scan_signature);
        $this->assertNotNull($file->scan_completed_at);
        $this->assertNull($file->clean_object_key);
        $this->assertNull($file->quarantine_object_key);
        $this->assertSame([], Storage::disk('clean')->allFiles());
        $this->assertSame([], Storage::disk('quarantine')->allFiles());
    }

    public function test_scanner_error_stays_fail_closed_and_final_failure_becomes_scan_failed(): void
    {
        $file = $this->quarantinedFile('retry fixture');
        $job = new ScanStoredFile($file->id);
        $exception = new ScannerException('scanner unavailable');

        try {
            $job->handle($this->scannerThrowing($exception));
            $this->fail('Scanner exception was expected.');
        } catch (ScannerException $caught) {
            $this->assertSame($exception, $caught);
        }

        $file->refresh();
        $this->assertSame('SCANNING', $file->state);
        Storage::disk('quarantine')->assertExists($file->quarantine_object_key);

        $job->failed($exception);
        $file->refresh();

        $this->assertSame('SCAN_FAILED', $file->state);
        $this->assertSame('clamav', $file->scan_engine);
        $this->assertNull($file->clean_object_key);
        $this->assertNotNull($file->quarantine_object_key);
        Storage::disk('quarantine')->assertExists($file->quarantine_object_key);
    }

    public function test_terminal_file_is_not_scanned_again(): void
    {
        $file = $this->quarantinedFile('terminal fixture');
        $file->forceFill([
            'state' => 'REJECTED',
            'scan_engine' => 'clamav',
            'scan_completed_at' => now(),
        ])->save();

        $job = new ScanStoredFile($file->id);
        $job->handle($this->scannerThrowing(new ScannerException('must not be called')));

        $this->assertSame('REJECTED', $file->fresh()->state);
    }

    public function test_scan_signature_is_internal_metadata_and_not_exposed_by_file_api(): void
    {
        $file = $this->quarantinedFile('metadata fixture');
        $job = new ScanStoredFile($file->id);
        $job->handle($this->scannerReturning(MalwareScanResult::unsafe('Internal-Signature')));

        Sanctum::actingAs($file->owner);

        $this->getJson('/api/v1/files/'.$file->id)
            ->assertOk()
            ->assertJsonPath('data.state', 'REJECTED')
            ->assertJsonMissingPath('data.scan_signature')
            ->assertJsonMissingPath('data.scan_engine')
            ->assertJsonMissingPath('data.quarantine_object_key')
            ->assertJsonMissingPath('data.clean_object_key');
    }

    private function quarantinedFile(string $content): StoredFile
    {
        $owner = User::query()->create([
            'name' => 'Scan Owner',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'StrongPass123!',
        ]);

        $file = new StoredFile([
            'original_name' => 'fixture.txt',
            'detected_mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'state' => 'QUARANTINED',
        ]);
        $owner->storedFiles()->save($file);
        $file->refresh();

        $key = $owner->id.'/'.$file->id;
        Storage::disk('quarantine')->put($key, $content);
        $file->forceFill(['quarantine_object_key' => $key])->save();

        return $file->refresh();
    }

    private function scannerReturning(MalwareScanResult $result): MalwareScanner
    {
        return new class($result) implements MalwareScanner
        {
            public function __construct(private readonly MalwareScanResult $result) {}

            public function scan(StoredFile $file): MalwareScanResult
            {
                return $this->result;
            }
        };
    }

    private function scannerThrowing(ScannerException $exception): MalwareScanner
    {
        return new class($exception) implements MalwareScanner
        {
            public function __construct(private readonly ScannerException $exception) {}

            public function scan(StoredFile $file): MalwareScanResult
            {
                throw $this->exception;
            }
        };
    }
}
