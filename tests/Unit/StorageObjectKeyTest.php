<?php

namespace Tests\Unit;

use App\Files\StorageObjectKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StorageObjectKeyTest extends TestCase
{
    public function test_canonical_object_key_uses_server_owned_uuid_path_segments(): void
    {
        $ownerId = '11111111-1111-4111-8111-111111111111';
        $fileId = '22222222-2222-4222-8222-222222222222';

        $this->assertSame(
            $ownerId.'/'.$fileId,
            StorageObjectKey::forOwnerFile($ownerId, $fileId),
        );
    }

    public function test_non_uuid_identifiers_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StorageObjectKey::forOwnerFile('not-a-uuid', '22222222-2222-4222-8222-222222222222');
    }
}
