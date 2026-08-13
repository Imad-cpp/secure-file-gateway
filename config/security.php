<?php

return [
    'auth_rate_limit_per_minute' => (int) env('AUTH_RATE_LIMIT_PER_MINUTE', 5),
    'upload_rate_limit_per_minute' => (int) env('UPLOAD_RATE_LIMIT_PER_MINUTE', 10),
    'download_rate_limit_per_minute' => (int) env('DOWNLOAD_RATE_LIMIT_PER_MINUTE', 20),
    'download_content_rate_limit_per_minute' => (int) env('DOWNLOAD_CONTENT_RATE_LIMIT_PER_MINUTE', 60),
    'download_url_ttl_seconds' => (int) env('DOWNLOAD_URL_TTL_SECONDS', 300),
    'api_token_ttl_minutes' => (int) env('API_TOKEN_TTL_MINUTES', 720),
];
