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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'face' => [
        'url' => env('FACE_SERVICE_URL', 'http://127.0.0.1:5000'),
        'threshold' => env('FACE_MATCH_THRESHOLD', 0.40),
    ],

    'wa' => [
        // WA Gateway self-hosted milik user: D:\Projects\wa (https://wa.kreasikaryaarjuna.co.id)
        'gateway_url' => env('WA_GATEWAY_URL', 'https://wa.kreasikaryaarjuna.co.id'),
        // API key device yang dibuat di dashboard gateway, dipakai untuk kirim balasan + verifikasi webhook
        'device_api_key' => env('WA_DEVICE_API_KEY'),
    ],

];
