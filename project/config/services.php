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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'genius' => [
        'ocean' => 'https://geniusocean.com/verify/'
    ],
    'choice' => [
        'base_url'    => env('CHOICE_BASE_URL'),
        'sender'      => env('CHOICE_SENDER'),
        'private_key' => env('CHOICE_PRIVATE_KEY'),
        'locale'      => env('CHOICE_LOCALE', 'en_KE'),
        'timeout'     => (int) env('CHOICE_TIMEOUT', 30),
    ],

];
