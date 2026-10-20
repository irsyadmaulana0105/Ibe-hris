<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'holiday_api' => [
        'enabled' => env('HOLIDAY_API_ENABLED', true),
        'timeout' => env('HOLIDAY_API_TIMEOUT', 8),
    ],

    'google_drive' => [
        // Path RELATIVE to base_path() - e.g. storage/app/google-credentials.json
        'key_path'       => env('GOOGLE_SERVICE_ACCOUNT_KEY_PATH'),
        // Optional: parent folder to upload into. Share this folder with the
        // service account email in Google Drive UI.
        'root_folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
    ],

    'stalwart' => [
        'url'            => env('STALWART_ADMIN_URL', 'https://mail.ibereality.com'),
        'admin_user'     => env('STALWART_ADMIN_USER'),
        'admin_password' => env('STALWART_ADMIN_PASSWORD'),
        'domain'         => env('STALWART_MAIL_DOMAIN', 'ibereality.com'),
        'cli_path'       => env('STALWART_CLI_PATH', '/usr/local/bin/stalwart-cli'),
        'webmail_url'    => env('STALWART_WEBMAIL_URL', 'https://webmail.ibereality.com'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'recaptcha' => [
        'enabled' => env('RECAPTCHA_ENABLED', true),
        'site' => env('RECAPTCHA_SITE_KEY'),
        'secret' => env('RECAPTCHA_SECRET_KEY'),
    ],

];
