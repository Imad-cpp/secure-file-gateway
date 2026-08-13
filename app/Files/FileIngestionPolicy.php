<?php

namespace App\Files;

use LogicException;

final readonly class FileIngestionPolicy
{
    /** @var array<string, list<string>> */
    private array $allowedTypes;

    private int $maxBytes;

    /**
     * @param  array<string, list<string>>|null  $allowedTypes
     */
    public function __construct(?int $maxBytes = null, ?array $allowedTypes = null)
    {
        $resolvedMaxBytes = $maxBytes ?? config('file_ingestion.max_bytes');
        $resolvedAllowedTypes = $allowedTypes ?? config('file_ingestion.allowed_types');

        if (! is_int($resolvedMaxBytes) || $resolvedMaxBytes <= 0) {
            throw new LogicException('The configured file size limit must be a positive integer.');
        }

        if (! is_array($resolvedAllowedTypes) || $resolvedAllowedTypes === []) {
            throw new LogicException('The configured file allowlist must be a non-empty array.');
        }

        $normalized = [];

        foreach ($resolvedAllowedTypes as $extension => $mimeTypes) {
            if (! is_string($extension) || $extension === '' || ! is_array($mimeTypes) || $mimeTypes === []) {
                throw new LogicException('Each allowed extension must map to one or more MIME types.');
            }

            $normalizedMimes = [];

            foreach ($mimeTypes as $mimeType) {
                if (! is_string($mimeType) || $mimeType === '') {
                    throw new LogicException('Allowed MIME types must be non-empty strings.');
                }

                $normalizedMimes[] = $mimeType;
            }

            $normalized[strtolower($extension)] = array_values(array_unique($normalizedMimes));
        }

        $this->maxBytes = $resolvedMaxBytes;
        $this->allowedTypes = $normalized;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function exceedsMaxBytes(int $sizeBytes): bool
    {
        return $sizeBytes > $this->maxBytes;
    }

    public function allowsExtension(string $extension): bool
    {
        return array_key_exists(strtolower($extension), $this->allowedTypes);
    }

    public function allowsMime(string $extension, string $mimeType): bool
    {
        return in_array($mimeType, $this->allowedTypes[strtolower($extension)] ?? [], true);
    }

    /** @return array<string, list<string>> */
    public function allowedTypes(): array
    {
        return $this->allowedTypes;
    }
}
