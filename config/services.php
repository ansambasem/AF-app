<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
    |--------------------------------------------------------------------------
    | Messages to subscribers
    |--------------------------------------------------------------------------
    |
    | How SMS messages leave the app. "log" only writes them to the log
    | (nothing is sent); "http" sends them through any provider with a plain
    | HTTP API, described by the fields below. WhatsApp messages need no
    | setup: staff send them from their own WhatsApp. The country code turns
    | local numbers ("059…") into international ones ("97059…").
    |
    */

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'country_code' => env('SMS_COUNTRY_CODE', '970'),
        'http' => [
            'url' => env('SMS_HTTP_URL'),
            'method' => env('SMS_HTTP_METHOD', 'post'),
            'token' => env('SMS_HTTP_TOKEN'),
            'to_param' => env('SMS_HTTP_TO_PARAM', 'to'),
            'message_param' => env('SMS_HTTP_MESSAGE_PARAM', 'message'),
            'sender_param' => env('SMS_HTTP_SENDER_PARAM', 'sender'),
            'sender' => env('SMS_HTTP_SENDER'),
            'extra_params' => env('SMS_HTTP_EXTRA_PARAMS'),
            'success_match' => env('SMS_HTTP_SUCCESS_MATCH'),
            'phone_format' => env('SMS_HTTP_PHONE_FORMAT', 'international'),
        ],
    ],

];
