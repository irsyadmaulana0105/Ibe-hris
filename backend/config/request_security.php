<?php

return [
    'max_body_bytes' => (int) env('API_MAX_BODY_BYTES', 52_428_800),
    'allowed_content_types' => [
        'application/json',
        'application/x-www-form-urlencoded',
        'multipart/form-data',
    ],
    'sensitive_paths' => [
        'api/v1/auth/*',
        'sso/*',
        'api/v1/admin/*',
        'api/v1/treasury/*',
        'api/v1/payroll*',
    ],
];
