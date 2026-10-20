<?php

return [
    // Disable only during a documented legacy-device migration window.
    'hardware_push_require_signature' => (bool) env('HARDWARE_PUSH_REQUIRE_SIGNATURE', true),
];
