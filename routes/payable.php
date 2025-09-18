<?php

use Illuminate\Support\Facades\Route;
use Ideacrafters\EloquentPayable\Http\Controllers\WebhookController;
use Ideacrafters\EloquentPayable\Http\Controllers\CallbackController;
use Ideacrafters\EloquentPayable\Http\Controllers\RedirectController;

/*
|--------------------------------------------------------------------------
| Payable Routes
|--------------------------------------------------------------------------
|
| Here are the routes that are automatically registered by the eloquent-payable
| package. You can disable these routes by setting PAYABLE_ROUTES_ENABLED=false
| in your .env file and register your own custom routes.
|
*/

if (config('payable.routes.enabled', true)) {
    $prefix = config('payable.routes.prefix', 'payable');
    $middleware = config('payable.routes.middleware', ['web']);

    Route::prefix($prefix)
        ->middleware($middleware)
        ->group(function () {
            // Webhook routes
            Route::post('/webhooks/stripe', [WebhookController::class, 'stripe'])
                ->withoutMiddleware(['web'])
                ->middleware(['throttle:webhooks']);

            Route::post('/webhooks/{processor}', [WebhookController::class, 'handle'])
                ->withoutMiddleware(['web'])
                ->middleware(['throttle:webhooks']);

            // Callback routes
            Route::get('/callback/success', [CallbackController::class, 'success'])
                ->name('payable.callback.success');

            Route::get('/callback/cancel', [CallbackController::class, 'cancel'])
                ->name('payable.callback.cancel');

            Route::get('/callback/failed', [CallbackController::class, 'failed'])
                ->name('payable.callback.failed');

            // Redirect routes for payment processors
            Route::get('/redirect/success', [RedirectController::class, 'success'])
                ->name('payable.redirect.success');

            Route::get('/redirect/cancel', [RedirectController::class, 'cancel'])
                ->name('payable.redirect.cancel');

            Route::get('/redirect/failed', [RedirectController::class, 'failed'])
                ->name('payable.redirect.failed');
        });
}
