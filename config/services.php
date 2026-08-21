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

    'partner' => [
        'api_url'   => env('API_URL'),
        'jwt_token' => env('API_TOKEN_JWT'),
    ],

    'order_reports' => [
        'recipient' => env('ORDER_REPORT_EMAIL'),
    ],

    'nova_poshta' => [
        'api_key' => env('NOVA_POSHTA_API_KEY'),

        'api_url' => env(
            'NOVA_POSHTA_API_URL',
            'https://api.novaposhta.ua/v2.0/json/'
        ),

        'sender_ref' => env(
            'NOVA_POSHTA_SENDER_REF'
        ),

        'contact_sender_ref' => env(
            'NOVA_POSHTA_CONTACT_SENDER_REF'
        ),

        'sender_address_ref' => env(
            'NOVA_POSHTA_SENDER_ADDRESS_REF'
        ),

        'sender_city_ref' => env(
            'NOVA_POSHTA_SENDER_CITY_REF'
        ),

        'sender_phone' => env(
            'NOVA_POSHTA_SENDER_PHONE'
        ),

        'sender_location_type' => env(
            'NOVA_POSHTA_SENDER_LOCATION_TYPE',
            'Warehouse'
        ),

        'payer_type' => env(
            'NOVA_POSHTA_PAYER_TYPE',
            'Sender'
        ),

        'payment_method' => env(
            'NOVA_POSHTA_PAYMENT_METHOD',
            'Cash'
        ),

        'cargo_type' => env(
            'NOVA_POSHTA_CARGO_TYPE',
            'Cargo'
        ),

        'cod_mode' => env(
            'NOVA_POSHTA_COD_MODE',
            'backward'
        ),
    ],
];
