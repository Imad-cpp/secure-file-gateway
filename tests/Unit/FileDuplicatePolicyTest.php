<?php

namespace Tests\Unit;

use App\Files\FileDuplicatePolicy;
use PHPUnit\Framework\TestCase;

class FileDuplicatePolicyTest extends TestCase
{
    public function test_duplicate_criteria_include_owner_and_digest(): void
    {
        $policy = new FileDuplicatePolicy;
        $digest = str_repeat('a', 64);

        $first = $policy->criteria('first-owner', $digest);
        $second = $policy->criteria('second-owner', $digest);

        $this->assertSame('first-owner', $first['owner_id']);
        $this->assertSame($digest, $first['sha256']);
        $this->assertSame($digest, $second['sha256']);
        $this->assertNotSame($first['owner_id'], $second['owner_id']);
        $this->assertNotSame($first, $second);
    }
}
