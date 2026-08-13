<?php

namespace Tests\Unit;

use App\Security\AuditMetadataSanitizer;
use PHPUnit\Framework\TestCase;

class AuditMetadataSanitizerTest extends TestCase
{
    public function test_sensitive_keys_are_removed_recursively_and_strings_are_bounded(): void
    {
        $sanitizer = new AuditMetadataSanitizer;
        $safe = $sanitizer->sanitize([
            'state' => 'AVAILABLE',
            'token' => 'secret-token',
            'signed_url' => 'https://example.test/private?signature=secret',
            'clean_object_key' => 'owner/file',
            'nested' => [
                'Authorization' => 'Bearer secret',
                'reason' => 'safe',
                'deep' => [
                    'note' => str_repeat('x', 300),
                    'payload' => 'hidden',
                    'too_deep' => [
                        'value' => 'discarded',
                    ],
                ],
            ],
            'count' => 3,
            'flag' => true,
            'nullable' => null,
            'object' => new \stdClass,
        ]);

        $this->assertSame('AVAILABLE', $safe['state']);
        $this->assertSame(3, $safe['count']);
        $this->assertTrue($safe['flag']);
        $this->assertNull($safe['nullable']);
        $this->assertArrayNotHasKey('token', $safe);
        $this->assertArrayNotHasKey('signed_url', $safe);
        $this->assertArrayNotHasKey('clean_object_key', $safe);
        $this->assertArrayNotHasKey('object', $safe);
        $this->assertSame('safe', $safe['nested']['reason']);
        $this->assertArrayNotHasKey('Authorization', $safe['nested']);
        $this->assertArrayNotHasKey('payload', $safe['nested']['deep']);
        $this->assertSame(255, strlen($safe['nested']['deep']['note']));
        $this->assertSame([], $safe['nested']['deep']['too_deep']);
    }
}
