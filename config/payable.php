<?php

use Ideacrafters\EloquentPayable\Processors\ProcessorNames;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Processor
    |--------------------------------------------------------------------------
    |
    | This option controls the default payment processor that will be used
    | when no processor is explicitly specified in the payment request.
    |
    */
    'default_processor' => env('PAYABLE_PROCESSOR', ProcessorNames::STRIPE),

    /*
    |--------------------------------------------------------------------------
    | Payment Processors
    |--------------------------------------------------------------------------
    |
    | Here you may configure the available payment processors. You can add
    | custom processors by implementing the PaymentProcessor interface.
    |
    */
    'processors' => [
        ProcessorNames::STRIPE => \Ideacrafters\EloquentPayable\Processors\StripeProcessor::class,
        ProcessorNames::SLICKPAY => \Ideacrafters\EloquentPayable\Processors\SlickpayProcessor::class,
        ProcessorNames::OFFLINE => \Ideacrafters\EloquentPayable\Processors\OfflineProcessor::class,
        ProcessorNames::NONE => \Ideacrafters\EloquentPayable\Processors\NoProcessor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Here you may configure the table names used by the package. This allows
    | you to avoid naming conflicts with existing tables in your application.
    |
    */
    'tables' => [
        'payments' => 'payments',
        'subscriptions' => 'subscriptions', // Future feature
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Classes
    |--------------------------------------------------------------------------
    |
    | Here you may configure the model classes used by the package. This allows
    | you to extend the default models with your own implementations.
    |
    */
    'models' => [
        'payment' => \Ideacrafters\EloquentPayable\Models\Payment::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency Settings
    |--------------------------------------------------------------------------
    |
    | Here you may configure the default currency and decimal precision
    | for payment amounts.
    |
    */
    'currency' => env('PAYABLE_CURRENCY', 'USD'),
    'decimal_precision' => 2,

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the routes that will be automatically registered
    | by the package. You can disable routes entirely or customize the prefix
    | and middleware.
    |
    */
    'routes' => [
        'enabled' => env('PAYABLE_ROUTES_ENABLED', true),
        'prefix' => env('PAYABLE_ROUTE_PREFIX', 'payable'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Statuses
    |--------------------------------------------------------------------------
    |
    | Here you may configure the payment statuses used throughout the package.
    | You can customize these to match your application's requirements.
    |
    */
    'statuses' => [
        'pending' => 'pending',
        'processing' => 'processing',
        'completed' => 'completed',
        'failed' => 'failed',
        'refunded' => 'refunded',
        'partially_refunded' => 'partially_refunded',
        'canceled' => 'canceled',
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure Stripe-specific settings. Make sure to set your
    | Stripe keys in your .env file.
    |
    */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_version' => env('STRIPE_API_VERSION', '2020-08-27'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slickpay Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure Slickpay-specific settings. Make sure to set your
    | Slickpay API key in your .env file.
    |
    */
    'slickpay' => [
        'api_key' => env('SLICKPAY_API_KEY'),
        'sandbox_mode' => env('SLICKPAY_SANDBOX_MODE', true),
        'dev_api' => env('SLICKPAY_DEV_API', 'https://devapi.slick-pay.com/api/v2'),
        'prod_api' => env('SLICKPAY_PROD_API', 'https://prodapi.slick-pay.com/api/v2'),
        'fallbacks' => [
            'first_name' => env('SLICKPAY_FALLBACK_FIRST_NAME', 'Customer'),
            'last_name' => env('SLICKPAY_FALLBACK_LAST_NAME', 'User'),
            'address' => env('SLICKPAY_FALLBACK_ADDRESS', 'Not provided'),
            'phone' => env('SLICKPAY_FALLBACK_PHONE', '0000000000'),
            'email' => env('SLICKPAY_FALLBACK_EMAIL', 'customer@example.com'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure webhook settings for payment processors.
    |
    */
    'webhooks' => [
        'verify_signature' => env('PAYABLE_VERIFY_WEBHOOK_SIGNATURE', true),
        'timeout' => env('PAYABLE_WEBHOOK_TIMEOUT', 30),
        'event_idempotency_ttl_days' => env('PAYABLE_WEBHOOK_EVENT_IDEMPOTENCY_TTL_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure whether payment events should be emitted.
    | You can disable events globally or per-processor.
    |
    */
    'events' => [
        'enabled' => env('PAYABLE_EVENTS_ENABLED', true),
        'processors' => [
            // ProcessorNames::STRIPE => false,  // Disable events for Stripe processor
            // ProcessorNames::SLICKPAY => false, // Disable events for Slickpay processor
            // ProcessorNames::OFFLINE => false,  // Disable events for Offline processor
            // ProcessorNames::NONE => false,     // Disable events for No processor
        ],
    ],
];
