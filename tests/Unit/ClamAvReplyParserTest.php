<?php

namespace Tests\Unit;

use App\Exceptions\ScannerException;
use App\Scanning\ClamAvReplyParser;
use App\Scanning\MalwareScanVerdict;
use PHPUnit\Framework\TestCase;

class ClamAvReplyParserTest extends TestCase
{
    public function test_ok_reply_is_clean(): void
    {
        $result = (new ClamAvReplyParser)->parse("stream: OK\0");

        $this->assertSame(MalwareScanVerdict::CLEAN, $result->verdict);
        $this->assertNull($result->signature);
    }

    public function test_found_reply_is_unsafe_and_keeps_only_signature(): void
    {
        $result = (new ClamAvReplyParser)->parse("stream: Eicar-Test-Signature FOUND\0");

        $this->assertSame(MalwareScanVerdict::UNSAFE, $result->verdict);
        $this->assertSame('Eicar-Test-Signature', $result->signature);
    }

    public function test_error_reply_fails_closed(): void
    {
        $this->expectException(ScannerException::class);

        (new ClamAvReplyParser)->parse("stream: scanner failure ERROR\0");
    }

    public function test_empty_reply_fails_closed(): void
    {
        $this->expectException(ScannerException::class);

        (new ClamAvReplyParser)->parse("\0");
    }
}
