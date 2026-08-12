<?php

return [
    'auth_rate_limit_per_minute' => (int) env('AUTH_RATE_LIMIT_PER_MINUTE', 5),
    'upload_rate_limit_per_minute' => (int) env('UPLOAD_RATE_LIMIT_PER_MINUTE', 10),
    'api_token_ttl_minutes' => (int) env('API_TOKEN_TTL_MINUTES', 720),
];
