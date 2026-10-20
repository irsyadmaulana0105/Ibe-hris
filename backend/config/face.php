<?php

return [
    // 'human'  = self-hosted engine, biometric template stays in our database.
    // 'biznet' = Biznet Face API (fr.neoapi.id), enrollment-based, DEMO ONLY.
    // Leave unset in production so the default keeps the current behaviour.
    'driver' => env('FACE_ENGINE_DRIVER', 'human'),

    'engine_url' => env('FACE_ENGINE_URL', 'http://127.0.0.1:3899'),
    'engine_timeout' => (float) env('FACE_ENGINE_TIMEOUT', 20),
    'engine_max_image_side' => (int) env('FACE_ENGINE_MAX_IMAGE_SIDE', 320),
    'match_similarity_threshold' => (float) env('FACE_MATCH_SIMILARITY_THRESHOLD', 0.60),

    // Token rahasia ke face-engine (header x-face-engine-token). Nilainya hanya
    // di env file root-owned di server, sama dengan env engine.
    'engine_token' => env('FACE_ENGINE_TOKEN'),

    /*
    | Pemeriksaan wajah hidup (anti foto cetak / layar HP), 19 Okt 2026.
    |
    | shadow  = skor dicatat & absen mencurigakan DITANDAI untuk HR, tidak ditolak.
    | enforce = absen dengan skor di bawah ambang DITOLAK.
    |
    | Mulai di shadow; ambang disetel dari data nyata (kolom face_check_in/out)
    | sebelum beralih ke enforce. Skor 0..1 dari model Human (liveness & antispoof).
    */
    'liveness_mode' => env('FACE_LIVENESS_MODE', 'shadow'),
    'liveness_min' => (float) env('FACE_LIVENESS_MIN', 0.5),
    'antispoof_min' => (float) env('FACE_ANTISPOOF_MIN', 0.5),
    // Kecocokan sedikit di atas ambang = ditandai "borderline" untuk ditinjau.
    'borderline_margin' => (float) env('FACE_BORDERLINE_MARGIN', 0.05),
    // Wajah lebih kecil dari ini (px, sisi terpendek) = ditandai "small_face".
    'small_face_px' => (int) env('FACE_SMALL_FACE_PX', 80),

    'biznet' => [
        'base_url' => env('FACE_BIZNET_URL', 'https://fr.neoapi.id/risetai/face-api'),
        'token' => env('FACE_BIZNET_TOKEN'),
        'facegallery_id' => env('FACE_BIZNET_FACEGALLERY_ID'),
        'timeout' => (float) env('FACE_BIZNET_TIMEOUT', 20),
    ],
];
