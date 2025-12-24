<?php

namespace Ideacrafters\EloquentPayable;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Ideacrafters\EloquentPayable\Http\Controllers\WebhookController;
use Ideacrafters\EloquentPayable\Http\Controllers\CallbackController;
use Ideacrafters\EloquentPayable\Http\Controllers\RedirectController;
use Ideacrafters\EloquentPayable\Processors\ProcessorNames;
use Ideacrafters\EloquentPayable\Processors\StripeWebhookHandler;

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

        // Register Stripe webhook handler (can be rebound by users in their service provider)
        $this->app->singleton(StripeWebhookHandler::class, function ($app) {
            return new StripeWebhookHandler();
        });

        $this->app->singleton(\Ideacrafters\EloquentPayable\Processors\StripeProcessor::class);
        $this->app->singleton(\Ideacrafters\EloquentPayable\Processors\OfflineProcessor::class);
        $this->app->singleton(\Ideacrafters\EloquentPayable\Processors\NoProcessor::class);
        $this->app->singleton(\Ideacrafters\EloquentPayable\Processors\SatimProcessor::class);
        
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

        // Register view namespace
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'payable');
        
        // Allow users to publish views
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/payable'),
        ], 'views');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Validate events.processors configuration
        $this->validateEventsProcessorsConfig();

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

    /**
     * Validate that processor names in events.processors are registered in processors config.
     * 
     * This ensures that if users want to disable events for a processor, they must first
     * register that processor in the 'processors' config. Custom processors should be
     * registered before configuring event settings.
     *
     * @return void
     */
    protected function validateEventsProcessorsConfig(): void
    {
        $eventsProcessors = Config::get('payable.events.processors', []);
        $registeredProcessors = array_keys(Config::get('payable.processors', []));

        if (empty($eventsProcessors)) {
            return; // No events.processors configured, nothing to validate
        }

        $invalidProcessors = [];

        foreach ($eventsProcessors as $processorName => $enabled) {
            if (!in_array($processorName, $registeredProcessors, true)) {
                $invalidProcessors[] = $processorName;
            }
        }

        if (!empty($invalidProcessors)) {
            $validProcessorNames = implode(', ', $registeredProcessors);
            $invalidProcessorNames = implode(', ', $invalidProcessors);
            
            Log::warning(
                "Eloquent Payable: Invalid processor names found in 'payable.events.processors' config. " .
                "The following processors are not registered in 'payable.processors': {$invalidProcessorNames}. " .
                "Registered processors are: {$validProcessorNames}. " .
                "Please register your custom processors in 'payable.processors' before configuring event settings. " .
                "For built-in processors, use ProcessorNames constants (e.g., ProcessorNames::STRIPE) to avoid typos."
            );
        }
    }
}
