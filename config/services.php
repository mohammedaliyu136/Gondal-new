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

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY', ''),
        'secret_key' => env('PAYSTACK_SECRET_KEY', ''),
        'merchant_email' => env('PAYSTACK_MERCHANT_EMAIL', ''),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

    'monnify' => [
        'api_key' => env('MONNIFY_API_KEY', ''),
        'secret_key' => env('MONNIFY_SECRET_KEY', ''),
        'contract_code' => env('MONNIFY_CONTRACT_CODE', ''),
        'base_url' => env('MONNIFY_BASE_URL', 'https://sandbox.monnify.com'),
    ],

    'zainpay' => [
        'public_key' => env('ZAINPAY_PUBLIC_KEY', ''),
        'zainbox_code' => env('ZAINPAY_ZAINBOX_CODE', ''),
        'base_url' => env('ZAINPAY_BASE_URL', 'https://sandbox.zainpay.ng'),
    ],
];
