<?php

return [
    'driver' => env('SCANNER_DRIVER', 'clamav'),
    'host' => env('CLAMAV_HOST', 'clamav'),
    'port' => (int) env('CLAMAV_PORT', 3310),
    'connect_timeout_seconds' => (float) env('CLAMAV_CONNECT_TIMEOUT_SECONDS', 3),
    'read_timeout_seconds' => (int) env('CLAMAV_READ_TIMEOUT_SECONDS', 20),
    'chunk_bytes' => (int) env('CLAMAV_STREAM_CHUNK_BYTES', 8192),
];
