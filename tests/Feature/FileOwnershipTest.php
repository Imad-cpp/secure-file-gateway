<?php

namespace Tests\Feature;

use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/files')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_file_listing_is_scoped_to_authenticated_owner(): void
    {
        [$owner, $other] = $this->makeUsers();
        $owned = $this->makeFile($owner, 'owned.pdf');
        $foreign = $this->makeFile($other, 'foreign.pdf');
        $token = $owner->createToken('phpunit')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/files')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $owned->id);

        $this->assertNotSame($foreign->id, $response->json('data.0.id'));
    }

    public function test_owner_can_read_metadata_without_private_storage_keys(): void
    {
        [$owner] = $this->makeUsers();
        $file = $this->makeFile($owner, 'report.pdf');
        $token = $owner->createToken('phpunit')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/files/'.$file->id)
            ->assertOk()
            ->assertJsonPath('data.id', $file->id)
            ->assertJsonPath('data.original_name', 'report.pdf');

        $data = $response->json('data');

        $this->assertArrayNotHasKey('quarantine_object_key', $data);
        $this->assertArrayNotHasKey('clean_object_key', $data);
        $this->assertArrayNotHasKey('owner_id', $data);
    }

    public function test_foreign_owner_receives_not_found_to_avoid_enumeration(): void
    {
        [$owner, $other] = $this->makeUsers();
        $file = $this->makeFile($owner, 'private.pdf');
        $token = $other->createToken('phpunit')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/files/'.$file->id)
            ->assertNotFound();
    }

    private function makeUsers(): array
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-'.fake()->uuid().'@example.test',
            'password' => 'StrongPass123!',
        ]);

        $other = User::query()->create([
            'name' => 'Other',
            'email' => 'other-'.fake()->uuid().'@example.test',
            'password' => 'StrongPass123!',
        ]);

        return [$owner, $other];
    }

    private function makeFile(User $owner, string $name): StoredFile
    {
        return StoredFile::query()->create([
            'owner_id' => $owner->id,
            'original_name' => $name,
            'detected_mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', $owner->id.$name),
            'quarantine_object_key' => 'quarantine/private-object',
            'clean_object_key' => null,
            'state' => 'QUARANTINED',
        ]);
    }
}
