<?php

return [
    // Stalwart JMAP endpoint - di-call dari backend (server-side) untuk
    // poll inbox + send push. Default localhost karena Stalwart jalan di
    // VPS yang sama. Bisa di-override via env STALWART_URL.
    'stalwart_url' => env('STALWART_URL', 'http://127.0.0.1:8080'),
];
