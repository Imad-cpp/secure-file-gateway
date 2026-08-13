<?php

namespace App\Files;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class StorageObjectKey
{
    public static function forOwnerFile(string $ownerId, string $fileId): string
    {
        if (! Str::isUuid($ownerId) || ! Str::isUuid($fileId)) {
            throw new InvalidArgumentException('Storage object keys require server-owned UUID identifiers.');
        }

        return $ownerId.'/'.$fileId;
    }
}
