<?php

namespace Tests\Feature;

use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeletedFileReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_retries_private_object_cleanup_for_deleted_tombstones(): void
    {
        Storage::fake('quarantine');
        Storage::fake('clean');

        $owner = $this->makeUser();
        $file = StoredFile::query()->create([
            'owner_id' => $owner->id,
            'original_name' => 'deleted.pdf',
            'detected_mime_type' => 'application/pdf',
            'size_bytes' => 123,
            'sha256' => null,
            'deleted_sha256' => hash('sha256', 'deleted-bytes'),
            'quarantine_object_key' => 'quarantine/leftover',
            'clean_object_key' => 'clean/leftover',
            'state' => 'DELETED',
            'deleted_at' => now(),
        ]);

        Storage::disk('quarantine')->put('quarantine/leftover', 'untrusted');
        Storage::disk('clean')->put('clean/leftover', 'clean');

        $exit = Artisan::call('files:reconcile-deleted', ['--limit' => 100]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Processed 1 deleted file(s); cleaned 1; remaining 0.', Artisan::output());

        $file->refresh();

        $this->assertNull($file->quarantine_object_key);
        $this->assertNull($file->clean_object_key);
        Storage::disk('quarantine')->assertMissing('quarantine/leftover');
        Storage::disk('clean')->assertMissing('clean/leftover');
    }

    public function test_reconciliation_never_touches_non_deleted_files(): void
    {
        Storage::fake('quarantine');

        $owner = $this->makeUser();
        $file = StoredFile::query()->create([
            'owner_id' => $owner->id,
            'original_name' => 'active.pdf',
            'detected_mime_type' => 'application/pdf',
            'size_bytes' => 123,
            'sha256' => hash('sha256', 'active-bytes'),
            'quarantine_object_key' => 'quarantine/active',
            'clean_object_key' => null,
            'state' => 'QUARANTINED',
        ]);

        Storage::disk('quarantine')->put('quarantine/active', 'untrusted');

        $this->assertSame(0, Artisan::call('files:reconcile-deleted'));

        $file->refresh();

        $this->assertSame('quarantine/active', $file->quarantine_object_key);
        Storage::disk('quarantine')->assertExists('quarantine/active');
    }

    public function test_reconciliation_rejects_out_of_range_limit(): void
    {
        $this->assertSame(2, Artisan::call('files:reconcile-deleted', ['--limit' => 0]));
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-'.fake()->uuid().'@example.test',
            'password' => 'StrongPass123!',
        ]);
    }
}
