<?php

namespace App\Files;

use App\Models\StoredFile;

final class FileDuplicatePolicy
{
    /** @return array{owner_id: string, sha256: string} */
    public function criteria(string $ownerId, string $sha256): array
    {
        return [
            'owner_id' => $ownerId,
            'sha256' => $sha256,
        ];
    }

    public function exists(string $ownerId, string $sha256): bool
    {
        return StoredFile::query()
            ->where($this->criteria($ownerId, $sha256))
            ->exists();
    }
}
