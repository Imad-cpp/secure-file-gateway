<?php

namespace Tests\Feature;

use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class ControlledDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_capability_requires_authentication(): void
    {
        $user = $this->makeUser('owner');
        $file = $this->makeFile($user, 'AVAILABLE', 'clean/report');

        $this->postJson('/api/v1/files/'.$file->id.'/download')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_foreign_owner_cannot_issue_download_capability(): void
    {
        $owner = $this->makeUser('owner');
        $other = $this->makeUser('other');
        $file = $this->makeFile($owner, 'AVAILABLE', 'clean/report');

        $this->postJson(
            '/api/v1/files/'.$file->id.'/download',
            [],
            $this->authHeaders($other),
        )->assertNotFound();
    }

    public function test_non_available_file_cannot_issue_download_capability(): void
    {
        $owner = $this->makeUser('owner');
        $file = $this->makeFile($owner, 'QUARANTINED');

        $this->postJson(
            '/api/v1/files/'.$file->id.'/download',
            [],
            $this->authHeaders($owner),
        )
            ->assertConflict()
            ->assertJsonPath('error.code', 'FILE_NOT_AVAILABLE');
    }

    public function test_available_owner_receives_short_lived_signed_capability_without_cacheable_metadata(): void
    {
        config(['security.download_url_ttl_seconds' => 300]);

        $owner = $this->makeUser('owner');
        $file = $this->makeFile($owner, 'AVAILABLE', 'clean/report');

        $response = $this->postJson(
            '/api/v1/files/'.$file->id.'/download',
            [],
            $this->authHeaders($owner),
        )
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'expires_at']]);

        $this->assertPrivateNoStore($response);

        $url = (string) $response->json('data.url');

        $this->assertStringContainsString('/api/v1/files/'.$file->id.'/content', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_valid_signed_capability_streams_clean_file_without_authentication(): void
    {
        Storage::fake('clean');

        $owner = $this->makeUser('owner');
        $contents = "%PDF-1.4\nportfolio evidence\n";
        $file = $this->makeFile($owner, 'AVAILABLE', 'clean/report', $contents);
        Storage::disk('clean')->put($file->clean_object_key, $contents);

        $url = $this->issueDownloadUrl($owner, $file);

        $response = $this->get($this->requestTarget($url))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('report.pdf');

        $this->assertPrivateNoStore($response);
        $this->assertSame($contents, $response->streamedContent());
    }

    public function test_invalid_download_signature_returns_stable_api_error(): void
    {
        $owner = $this->makeUser('owner');
        $file = $this->makeFile($owner, 'AVAILABLE', 'clean/report');
        $url = $this->issueDownloadUrl($owner, $file);
        $target = $this->requestTarget($url);
        $tampered = preg_replace('/signature=[^&]+/', 'signature=invalid', $target) ?? $target;

        $this->getJson($tampered)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'INVALID_DOWNLOAD_SIGNATURE');
    }

    public function test_expired_download_signature_returns_stable_api_error(): void
    {
        config(['security.download_url_ttl_seconds' => 60]);

        $owner = $this->makeUser('owner');
        $file = $this->makeFile($owner, 'AVAILABLE', 'clean/report');
        $url = $this->issueDownloadUrl($owner, $file);

        $this->travel(61)->seconds();

        $this->getJson($this->requestTarget($url))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'INVALID_DOWNLOAD_SIGNATURE');
    }

    public function test_delete_requires_authentication(): void
    {
        $owner = $this->makeUser('owner');
        $file = $this->makeFile($owner, 'AVAILABLE', 'clean/private');

        $this->deleteJson('/api/v1/files/'.$file->id)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertSame('AVAILABLE', $file->fresh()->state);
    }

    public function test_owner_delete_is_idempotent_and_removes_clean_storage(): void
    {
        Storage::fake('clean');

        $owner = $this->makeUser('owner');
        $contents = "%PDF-1.4\ndelete me\n";
        $file = $this->makeFile($owner, 'AVAILABLE', 'clean/delete-me', $contents);
        $originalSha256 = $file->sha256;
        Storage::disk('clean')->put($file->clean_object_key, $contents);

        $endpoint = '/api/v1/files/'.$file->id;

        $this->deleteJson($endpoint, [], $this->authHeaders($owner))->assertNoContent();

        $file->refresh();

        $this->assertSame('DELETED', $file->state);
        $this->assertNull($file->sha256);
        $this->assertSame($originalSha256, $file->deleted_sha256);
        $this->assertNotNull($file->deleted_at);
        $this->assertNull($file->clean_object_key);
        Storage::disk('clean')->assertMissing('clean/delete-me');

        $this->deleteJson($endpoint, [], $this->authHeaders($owner))->assertNoContent();
    }

    public function test_owner_delete_removes_quarantined_storage(): void
    {
        Storage::fake('quarantine');

        $owner = $this->makeUser('owner');
        $file = $this->makeFile($owner, 'QUARANTINED');
        $file->forceFill(['quarantine_object_key' => 'quarantine/delete-me'])->save();
        Storage::disk('quarantine')->put('quarantine/delete-me', 'untrusted');

        $this->deleteJson(
            '/api/v1/files/'.$file->id,
            [],
            $this->authHeaders($owner),
        )->assertNoContent();

        $file->refresh();

        $this->assertSame('DELETED', $file->state);
        $this->assertNull($file->quarantine_object_key);
        Storage::disk('quarantine')->assertMissing('quarantine/delete-me');
    }

    public function test_storage_cleanup_failure_keeps_deleted_tombstone_and_object_key_for_retry(): void
    {
        $owner = $this->makeUser('owner');
        $file = $this->makeFile($owner, 'AVAILABLE', 'clean/pending-delete');
        $originalSha256 = $file->sha256;

        Storage::shouldReceive('disk')
            ->once()
            ->with('clean')
            ->andThrow(new RuntimeException('storage offline'));

        $this->deleteJson(
            '/api/v1/files/'.$file->id,
            [],
            $this->authHeaders($owner),
        )
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');

        $file->refresh();

        $this->assertSame('DELETED', $file->state);
        $this->assertNull($file->sha256);
        $this->assertSame($originalSha256, $file->deleted_sha256);
        $this->assertSame('clean/pending-delete', $file->clean_object_key);
        $this->assertNotNull($file->deleted_at);
    }

    public function test_foreign_owner_cannot_delete_file_or_storage_object(): void
    {
        Storage::fake('clean');

        $owner = $this->makeUser('owner');
        $other = $this->makeUser('other');
        $file = $this->makeFile($owner, 'AVAILABLE', 'clean/private');
        Storage::disk('clean')->put('clean/private', 'private bytes');

        $this->deleteJson(
            '/api/v1/files/'.$file->id,
            [],
            $this->authHeaders($other),
        )->assertNotFound();

        $this->assertSame('AVAILABLE', $file->fresh()->state);
        Storage::disk('clean')->assertExists('clean/private');
    }

    public function test_deletion_revokes_an_already_issued_capability(): void
    {
        Storage::fake('clean');

        $owner = $this->makeUser('owner');
        $contents = "%PDF-1.4\nrevoke me\n";
        $file = $this->makeFile($owner, 'AVAILABLE', 'clean/revoke', $contents);
        Storage::disk('clean')->put('clean/revoke', $contents);
        $url = $this->issueDownloadUrl($owner, $file);

        $this->deleteJson(
            '/api/v1/files/'.$file->id,
            [],
            $this->authHeaders($owner),
        )->assertNoContent();

        $this->getJson($this->requestTarget($url))
            ->assertConflict()
            ->assertJsonPath('error.code', 'FILE_NOT_AVAILABLE');
    }

    public function test_deleted_tombstone_releases_active_duplicate_hash_for_reupload(): void
    {
        Storage::fake('clean');

        $owner = $this->makeUser('owner');
        $file = $this->makeFile($owner, 'AVAILABLE', 'clean/first');
        $sha256 = $file->sha256;
        Storage::disk('clean')->put('clean/first', 'first');

        $this->deleteJson(
            '/api/v1/files/'.$file->id,
            [],
            $this->authHeaders($owner),
        )->assertNoContent();

        $replacement = StoredFile::query()->create([
            'owner_id' => $owner->id,
            'original_name' => 'replacement.pdf',
            'detected_mime_type' => 'application/pdf',
            'size_bytes' => 7,
            'sha256' => $sha256,
            'quarantine_object_key' => 'quarantine/replacement',
            'clean_object_key' => null,
            'state' => 'QUARANTINED',
        ]);

        $this->assertSame($sha256, $replacement->sha256);
    }

    public function test_download_capability_issuance_has_independent_rate_limit(): void
    {
        config(['security.download_rate_limit_per_minute' => 1]);

        $owner = $this->makeUser('owner');
        $file = $this->makeFile($owner, 'AVAILABLE', 'clean/report');
        $headers = $this->authHeaders($owner);
        $endpoint = '/api/v1/files/'.$file->id.'/download';

        $this->postJson($endpoint, [], $headers)->assertOk();
        $this->postJson($endpoint, [], $headers)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    private function makeUser(string $label): User
    {
        return User::query()->create([
            'name' => ucfirst($label),
            'email' => $label.'-'.fake()->uuid().'@example.test',
            'password' => 'StrongPass123!',
        ]);
    }

    private function makeFile(
        User $owner,
        string $state,
        ?string $cleanObjectKey = null,
        string $contents = 'placeholder',
    ): StoredFile {
        return StoredFile::query()->create([
            'owner_id' => $owner->id,
            'original_name' => 'report.pdf',
            'detected_mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $owner->id.$state.fake()->uuid()),
            'quarantine_object_key' => $state === 'QUARANTINED' ? 'quarantine/private-object' : null,
            'clean_object_key' => $cleanObjectKey,
            'state' => $state,
        ]);
    }

    private function authHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('phpunit')->plainTextToken,
        ];
    }

    private function issueDownloadUrl(User $owner, StoredFile $file): string
    {
        return (string) $this->postJson(
            '/api/v1/files/'.$file->id.'/download',
            [],
            $this->authHeaders($owner),
        )
            ->assertOk()
            ->json('data.url');
    }

    private function requestTarget(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        return $path.(is_string($query) && $query !== '' ? '?'.$query : '');
    }

    private function assertPrivateNoStore(TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
    }
}
