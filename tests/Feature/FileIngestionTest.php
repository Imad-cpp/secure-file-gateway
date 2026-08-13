<?php

namespace Tests\Feature;

use App\Jobs\ScanStoredFile;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FileIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_upload_requires_authentication(): void
    {
        $this->post('/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'private notes'),
        ], ['Accept' => 'application/json'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_allowed_file_is_written_to_private_quarantine_persisted_and_queued_for_scan(): void
    {
        Storage::fake('quarantine');
        $user = $this->actingUser();

        $response = $this->post('/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'secure gateway text fixture'),
        ], ['Accept' => 'application/json']);

        $response
            ->assertAccepted()
            ->assertJsonPath('data.original_name', 'notes.txt')
            ->assertJsonPath('data.detected_mime_type', 'text/plain')
            ->assertJsonPath('data.state', 'QUARANTINED')
            ->assertJsonMissingPath('data.quarantine_object_key')
            ->assertJsonMissingPath('data.clean_object_key')
            ->assertJsonMissingPath('data.owner_id');

        $storedFile = StoredFile::query()->firstOrFail();

        $this->assertSame($user->id, $storedFile->owner_id);
        $this->assertSame(hash('sha256', 'secure gateway text fixture'), $storedFile->sha256);
        $this->assertStringNotContainsString('notes.txt', $storedFile->quarantine_object_key);
        Storage::disk('quarantine')->assertExists($storedFile->quarantine_object_key);
        Queue::assertPushed(ScanStoredFile::class, fn (ScanStoredFile $job): bool => $job->fileId === $storedFile->id);
    }

    public function test_disallowed_extension_is_rejected_before_quarantine_write(): void
    {
        Storage::fake('quarantine');
        $this->actingUser();

        $this->post('/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('payload.exe', 'not executable bytes'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'FILE_TYPE_NOT_ALLOWED');

        $this->assertSame([], Storage::disk('quarantine')->allFiles());
        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_file_larger_than_ten_mib_is_rejected_before_quarantine_write(): void
    {
        Storage::fake('quarantine');
        $this->actingUser();

        $this->post('/api/v1/files', [
            'file' => UploadedFile::fake()->create('large.pdf', 10 * 1024 + 1, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'FILE_TOO_LARGE');

        $this->assertSame([], Storage::disk('quarantine')->allFiles());
        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_extension_and_server_detected_mime_must_agree_and_failed_object_is_cleaned_up(): void
    {
        Storage::fake('quarantine');
        $this->actingUser();

        $this->post('/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('spoofed.pdf', 'this is plain text'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'FILE_TYPE_MISMATCH');

        $this->assertSame([], Storage::disk('quarantine')->allFiles());
        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_duplicate_file_is_rejected_only_within_same_owner_and_new_quarantine_object_is_cleaned_up(): void
    {
        Storage::fake('quarantine');
        $this->actingUser();

        $content = 'same owner duplicate fixture';

        $this->post('/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('first.txt', $content),
        ], ['Accept' => 'application/json'])->assertAccepted();

        $this->post('/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('second.txt', $content),
        ], ['Accept' => 'application/json'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'DUPLICATE_FILE');

        $this->assertDatabaseCount('stored_files', 1);
        $this->assertCount(1, Storage::disk('quarantine')->allFiles());
    }

    public function test_same_hash_is_allowed_for_different_owners_without_cross_user_presence_signal(): void
    {
        Storage::fake('quarantine');
        $firstUser = User::query()->create([
            'name' => 'First Owner',
            'email' => 'first-owner@example.test',
            'password' => 'StrongPass123!',
        ]);
        $secondUser = User::query()->create([
            'name' => 'Second Owner',
            'email' => 'second-owner@example.test',
            'password' => 'StrongPass123!',
        ]);
        $content = 'shared bytes across independent owners';

        Sanctum::actingAs($firstUser);
        $this->post('/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('first.txt', $content),
        ], ['Accept' => 'application/json'])->assertAccepted();

        Sanctum::actingAs($secondUser);
        $this->post('/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('second.txt', $content),
        ], ['Accept' => 'application/json'])->assertAccepted();

        $this->assertDatabaseCount('stored_files', 2);
        $this->assertCount(2, Storage::disk('quarantine')->allFiles());
    }

    public function test_upload_creation_has_its_own_authenticated_rate_limit(): void
    {
        Storage::fake('quarantine');
        config()->set('security.upload_rate_limit_per_minute', 1);
        $this->actingUser();

        $this->post('/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('first.txt', 'first upload'),
        ], ['Accept' => 'application/json'])->assertAccepted();

        $this->post('/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('second.txt', 'second upload'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_scan_queue_failure_compensates_metadata_and_quarantine(): void
    {
        Storage::fake('quarantine');
        $this->actingUser();

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->post('/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('queue-failure.txt', 'queue failure fixture'),
        ], ['Accept' => 'application/json'])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');

        $this->assertDatabaseCount('stored_files', 0);
        $this->assertSame([], Storage::disk('quarantine')->allFiles());
    }

    private function actingUser(): User
    {
        $user = User::query()->create([
            'name' => 'Upload Owner',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'StrongPass123!',
        ]);

        Sanctum::actingAs($user);

        return $user;
    }
}
