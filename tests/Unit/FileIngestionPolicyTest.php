<?php

namespace Tests\Unit;

use App\Files\FileIngestionPolicy;
use LogicException;
use PHPUnit\Framework\TestCase;

class FileIngestionPolicyTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $allowedTypes = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'txt' => ['text/plain'],
    ];

    public function test_v1_allowlist_and_size_policy_are_explicit(): void
    {
        $policy = new FileIngestionPolicy(10 * 1024 * 1024, $this->allowedTypes);

        $this->assertSame(10 * 1024 * 1024, $policy->maxBytes());
        $this->assertTrue($policy->allowsExtension('pdf'));
        $this->assertTrue($policy->allowsExtension('JPG'));
        $this->assertFalse($policy->allowsExtension('zip'));
        $this->assertTrue($policy->allowsMime('jpeg', 'image/jpeg'));
        $this->assertTrue($policy->allowsMime('txt', 'text/plain'));
        $this->assertFalse($policy->allowsMime('txt', 'image/png'));
        $this->assertFalse($policy->exceedsMaxBytes(10 * 1024 * 1024));
        $this->assertTrue($policy->exceedsMaxBytes((10 * 1024 * 1024) + 1));
    }

    public function test_invalid_policy_configuration_fails_closed(): void
    {
        $this->expectException(LogicException::class);

        new FileIngestionPolicy(0, $this->allowedTypes);
    }
}
