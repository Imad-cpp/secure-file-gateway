<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SecurityAuditRecorder
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

    public function record(
        ?User $actor,
        string $action,
        string $outcome,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
    ): bool {
        $requestId = $this->requestId();

        try {
            AuditEvent::query()->create([
                'actor_id' => $actor?->getAuthIdentifier(),
                'action' => Str::limit($action, 120, ''),
                'target_type' => $targetType !== null ? Str::limit($targetType, 80, '') : null,
                'target_id' => $targetId,
                'outcome' => Str::limit($outcome, 40, ''),
                'request_id' => $requestId,
                'metadata' => $this->sanitizeMetadata($metadata),
            ]);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Security audit event persistence failed.', [
                'action' => Str::limit($action, 120, ''),
                'outcome' => Str::limit($outcome, 40, ''),
                'request_id' => $requestId,
            ]);

            return false;
        }
    }

    public function sanitizeMetadata(array $metadata, int $depth = 0): array
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
                $safe[$name] = $this->sanitizeMetadata($value, $depth + 1);

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

    private function requestId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $requestId = app('request')->attributes->get('request_id');

        return is_string($requestId) && Str::isUuid($requestId) ? $requestId : null;
    }
}
