<?php

namespace Tests\Unit;

use App\Files\FileLifecyclePolicy;
use PHPUnit\Framework\TestCase;

class FileLifecyclePolicyTest extends TestCase
{
    public function test_only_documented_lifecycle_transitions_are_allowed(): void
    {
        $this->assertTrue(FileLifecyclePolicy::canTransition(FileLifecyclePolicy::QUARANTINED, FileLifecyclePolicy::SCANNING));
        $this->assertTrue(FileLifecyclePolicy::canTransition(FileLifecyclePolicy::QUARANTINED, FileLifecyclePolicy::DELETED));
        $this->assertTrue(FileLifecyclePolicy::canTransition(FileLifecyclePolicy::SCANNING, FileLifecyclePolicy::AVAILABLE));
        $this->assertTrue(FileLifecyclePolicy::canTransition(FileLifecyclePolicy::SCANNING, FileLifecyclePolicy::REJECTED));
        $this->assertTrue(FileLifecyclePolicy::canTransition(FileLifecyclePolicy::SCANNING, FileLifecyclePolicy::SCAN_FAILED));
        $this->assertTrue(FileLifecyclePolicy::canTransition(FileLifecyclePolicy::AVAILABLE, FileLifecyclePolicy::DELETED));
        $this->assertTrue(FileLifecyclePolicy::canTransition(FileLifecyclePolicy::REJECTED, FileLifecyclePolicy::DELETED));
        $this->assertTrue(FileLifecyclePolicy::canTransition(FileLifecyclePolicy::SCAN_FAILED, FileLifecyclePolicy::DELETED));

        $this->assertFalse(FileLifecyclePolicy::canTransition(FileLifecyclePolicy::QUARANTINED, FileLifecyclePolicy::AVAILABLE));
        $this->assertFalse(FileLifecyclePolicy::canTransition(FileLifecyclePolicy::REJECTED, FileLifecyclePolicy::AVAILABLE));
        $this->assertFalse(FileLifecyclePolicy::canTransition(FileLifecyclePolicy::SCAN_FAILED, FileLifecyclePolicy::SCANNING));
        $this->assertFalse(FileLifecyclePolicy::canTransition(FileLifecyclePolicy::DELETED, FileLifecyclePolicy::AVAILABLE));
        $this->assertFalse(FileLifecyclePolicy::canTransition('UNKNOWN', FileLifecyclePolicy::AVAILABLE));
    }

    public function test_scan_and_download_gates_are_fail_closed(): void
    {
        $this->assertTrue(FileLifecyclePolicy::canScan(FileLifecyclePolicy::QUARANTINED));
        $this->assertTrue(FileLifecyclePolicy::canScan(FileLifecyclePolicy::SCANNING));
        $this->assertFalse(FileLifecyclePolicy::canScan(FileLifecyclePolicy::AVAILABLE));
        $this->assertFalse(FileLifecyclePolicy::canScan('UNKNOWN'));

        $this->assertTrue(FileLifecyclePolicy::canIssueDownload(FileLifecyclePolicy::AVAILABLE));
        $this->assertFalse(FileLifecyclePolicy::canIssueDownload(FileLifecyclePolicy::REJECTED));
        $this->assertFalse(FileLifecyclePolicy::canIssueDownload(FileLifecyclePolicy::DELETED));
        $this->assertFalse(FileLifecyclePolicy::canIssueDownload('UNKNOWN'));
    }
}
