<?php

return [
    // Enable on VPS so a broken R2 configuration never silently fills VPS disk.
    'require_r2' => (bool) env('PROJECT_ATTACHMENTS_REQUIRE_R2', false),

    // Release-bound source bridge only. Runtime API never exposes these URLs.
    'legacy_public_hosts' => [
        'attachments.ibereality.com',
        'pub-8511300fbe1e44d6a2df86169c6ea895.r2.dev',
    ],
    'legacy_runtime_expected_entries' => 2,

    // Keep active content (HTML/SVG/scripts) out of every storage provider.
    // Extensions and server-detected MIME types are both checked on upload.
    'allowed_extensions' => [
        'pdf', 'jpg', 'jpeg', 'png', 'webp',
        'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    ],
    'allowed_mime_types' => [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/webp',
        'text/plain', 'text/csv', 'application/csv',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // Fileinfo commonly reports OOXML containers as ZIP.
        'application/zip', 'application/x-zip-compressed',
    ],
    'inline_mime_types' => [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
    ],
    'mime_types_by_extension' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'], 'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/x-zip-compressed'],
    ],
];
