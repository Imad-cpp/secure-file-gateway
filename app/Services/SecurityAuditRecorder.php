<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Security\AuditMetadataSanitizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SecurityAuditRecorder
{
    public function __construct(
        private readonly AuditMetadataSanitizer $sanitizer,
    ) {}

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

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sanitizeMetadata(array $metadata, int $depth = 0): array
    {
        return $this->sanitizer->sanitize($metadata, $depth);
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
