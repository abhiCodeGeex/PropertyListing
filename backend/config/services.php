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

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'aadhaar' => [
        'base_url'   => env('AADHAAR_API_URL', 'https://api.sandbox.co.in/kyc/aadhaar/okyc'),
        'api_key'    => env('AADHAAR_API_KEY'),
        'api_secret' => env('AADHAAR_API_SECRET'),
        'api_token' => env('AADHAAR_API_TOKEN'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URL'),
    ],
    'facebook' => [
        'client_id'     => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect'      => env('FACEBOOK_REDIRECT_URL'),
    ],
    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret' => env('RECAPTCHA_SECRET'),
    ],
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        'client_id' => env('STRIPE_CLIENT_ID'),
        'rent_product_id' => env('STRIPE_RENT_PRODUCT_ID'),
        /** Lowercase ISO code — must match Stripe Dashboard and PaymentIntents (e.g. inr, usd). */
        'currency' => env('STRIPE_CURRENCY', 'inr'),
        /** Used for number formatting in emails/PDFs; keep aligned with the Angular app. */
        'currency_locale' => env('APP_CURRENCY_LOCALE', 'en-IN'),
    ],

];
