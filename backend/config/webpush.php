<?php

return [
    'vapid' => [
        'subject'     => env('VAPID_SUBJECT', 'mailto:admin@ibereality.com'),
        'public_key'  => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    // Berapa banyak parallel push request - Stalwart limit + push services
    // (FCM/Mozilla/etc) handle rate limit sendiri, default sequential cukup.
    'batch_size' => 50,

    // Auto-prune subscription kalau gagal push N kali berturut-turut.
    // Endpoint browser umumnya valid ~6 bulan, jadi 3-5 attempts cukup
    // signal subscription mati.
    'max_failures' => 5,
];
