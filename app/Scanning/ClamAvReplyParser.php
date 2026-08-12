<?php

namespace App\Scanning;

use App\Exceptions\ScannerException;

class ClamAvReplyParser
{
    public function parse(string $reply): MalwareScanResult
    {
        $reply = trim($reply, "\0\r\n \t");

        if ($reply === '') {
            throw new ScannerException('The malware scanner returned an empty response.');
        }

        if (str_ends_with($reply, ': OK')) {
            return MalwareScanResult::clean();
        }

        if (preg_match('/:\s+(.+)\s+FOUND$/', $reply, $matches) === 1) {
            return MalwareScanResult::unsafe($matches[1]);
        }

        throw new ScannerException('The malware scanner returned an unrecognized response.');
    }
}
