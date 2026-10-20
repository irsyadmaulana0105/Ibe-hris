<?php

return [
    'cookie' => env('WEBMAIL_GATEWAY_COOKIE', '__Host-ibe_mail_session'),
    'session_ttl_minutes' => (int) env('WEBMAIL_GATEWAY_SESSION_TTL', 480),
    'internal_secret' => env('WEBMAIL_GATEWAY_INTERNAL_SECRET'),
    // Emergency rollback only. Never enable permanently: legacy exchange exposes
    // mailbox passwords to browser JavaScript.
    'allow_legacy_credential_exchange' => env('WEBMAIL_ALLOW_LEGACY_CREDENTIAL_EXCHANGE', false),
];
