<?php

namespace Ideacrafters\EloquentPayable;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Ideacrafters\EloquentPayable\Http\Controllers\WebhookController;
use Ideacrafters\EloquentPayable\Http\Controllers\CallbackController;
use Ideacrafters\EloquentPayable\Http\Controllers\RedirectController;

class PayableServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/payable.php', 'payable'
        );

        $this->app->singleton(\Ideacrafters\EloquentPayable\Processors\StripeProcessor::class);
        $this->app->singleton(\Ideacrafters\EloquentPayable\Processors\OfflineProcessor::class);
        $this->app->singleton(\Ideacrafters\EloquentPayable\Processors\NoProcessor::class);
        
        $this->app->singleton('payable', function ($app) {
            return new \Ideacrafters\EloquentPayable\PayableManager();
        });
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/payable.php' => config_path('payable.php'),
        ], 'config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('payable.routes.enabled', true)) {
            $this->registerRoutes();
        }
    }

    /**
     * Register the package routes.
     *
     * @return void
     */
    protected function registerRoutes()
    {
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
}
