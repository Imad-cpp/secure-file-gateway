<?php

namespace App\Security;

use Illuminate\Support\Str;

final class AuditMetadataSanitizer
{
    private const BLOCKED_KEY_FRAGMENTS = [
        'authorization',
        'token',
        'password',
        'secret',
        'credential',
        'signature',
        'url',
        'object_key',
        'contents',
        'payload',
        'body',
    ];

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sanitize(array $metadata, int $depth = 0): array
    {
        if ($depth >= 3) {
            return [];
        }

        $safe = [];

        foreach ($metadata as $key => $value) {
            $name = (string) $key;
            $normalized = Str::lower($name);

            if ($this->isBlockedKey($normalized)) {
                continue;
            }

            if (is_array($value)) {
                $safe[$name] = $this->sanitize($value, $depth + 1);

                continue;
            }

            if (is_string($value)) {
                $safe[$name] = Str::limit($value, 255, '');

                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $safe[$name] = $value;
            }
        }

        return $safe;
    }

    private function isBlockedKey(string $key): bool
    {
        foreach (self::BLOCKED_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
