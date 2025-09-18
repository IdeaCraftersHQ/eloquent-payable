<?php

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
    'default_processor' => env('PAYABLE_PROCESSOR', 'stripe'),

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
        'stripe' => \Ideacrafters\EloquentPayable\Processors\StripeProcessor::class,
        'offline' => \Ideacrafters\EloquentPayable\Processors\OfflineProcessor::class,
        'none' => \Ideacrafters\EloquentPayable\Processors\NoProcessor::class,
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
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure webhook settings for payment processors.
    |
    */
    'webhooks' => [
        'verify_signature' => env('PAYABLE_VERIFY_WEBHOOK_SIGNATURE', true),
        'timeout' => env('PAYABLE_WEBHOOK_TIMEOUT', 30),
    ],
];
