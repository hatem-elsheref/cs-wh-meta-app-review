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

    /*
    | Isnaad portal: GET {base_url}/{order_number}
    | Example: https://portal.isnaad.sa/api/order-tracking/2062380
    */
    /*
    | External integrations: POST /api/external/whatsapp/templates/*
    | Send header: X-API-Key: <your key>
    */
    'external_whatsapp' => [
        'api_key' => env('EXTERNAL_WHATSAPP_API_KEY', ''),
    ],

    /*
    | WhatsApp flow bot (FlowEngine): inbound webhooks trigger automated replies from the configured flow.
    | Set WHATSAPP_FLOW_AUTOMATION=false to only store messages and use the dashboard for human replies.
    |
    | WHATSAPP_PROCESS_INCOMING_SYNC=true (default): run flow in the webhook request so replies are not
    | delayed by a backed-up queue. Set false and run `php artisan queue:work` if you prefer async.
    */
    'whatsapp' => [
        'flow_automation_enabled' => filter_var(
            env('WHATSAPP_FLOW_AUTOMATION', true),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        ) ?? true,
        'process_incoming_sync' => filter_var(
            env('WHATSAPP_PROCESS_INCOMING_SYNC', true),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        ) ?? true,
    ],

    'isnaad' => [
        // Use ?: so an empty .env value does not wipe the default (env('X','default') keeps '' if X is set blank).
        'order_tracking_base_url' => rtrim(
            (string) (env('ISNAAD_ORDER_TRACKING_BASE_URL') ?: 'https://portal.isnaad.sa/api/order-tracking'),
            '/'
        ),
        'order_missed_base_url' => rtrim(
            (string) (env('ISNAAD_ORDER_MISSED_BASE_URL') ?: 'https://portal.isnaad.sa/api/order-missed'),
            '/'
        ),
    ],

];
