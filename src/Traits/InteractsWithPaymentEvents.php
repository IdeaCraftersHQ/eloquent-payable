<?php

namespace Ideacrafters\EloquentPayable\Traits;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Trait for checking if events should be emitted.
 * Used by processors and payment models to respect event configuration.
 */
trait InteractsWithPaymentEvents
{
    /**
     * Check if events should be emitted.
     * 
     * Checks global events setting first, then processor-specific setting.
     * Processor-specific setting overrides global if set.
     *
     * @return bool
     */
    protected function shouldEmitEvents(): bool
    {
        // Check global events setting
        // Only use default true if config doesn't exist; if explicitly set to false, respect it
        $globalEnabled = Config::get('payable.events.enabled');
        if ($globalEnabled === false) {
            return false;
        }
        // If config doesn't exist, default to true (backward compatibility)
        if ($globalEnabled === null) {
            $globalEnabled = true;
        }

        // Check processor-specific setting (if set, it overrides global)
        $processorName = $this->getProcessorNameForEvents();
        
        if ($processorName) {
            $processorEventsConfig = Config::get("payable.events.processors.{$processorName}");
            
            if ($processorEventsConfig !== null) {
                return (bool) $processorEventsConfig;
            }
        }

        // Return global setting (defaults to true if not configured)
        return (bool) $globalEnabled;
    }

    /**
     * Get the processor name for event configuration checking.
     *
     * This method should be implemented by classes using this trait.
     * For processors, it should return the processor name.
     * For payments, it should return the payment's processor name.
     *
     * @return string|null
     */
    abstract protected function getProcessorNameForEvents(): ?string;

    /**
     * Fire an event safely, catching any exceptions from synchronous listeners.
     *
     * This ensures that payment state changes (which are committed before events fire)
     * are not affected by exceptions thrown in synchronous event listeners.
     * Exceptions are reported to Laravel's exception handler for visibility
     * in logs and monitoring tools, but are not rethrown.
     *
     * @param  object  $event  The event instance to fire
     * @return void
     */
    protected function fireEventSafely(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            // Report the exception to Laravel's exception handler for logging/monitoring
            // but don't rethrow - the payment state change has already been committed
            $this->reportEventListenerException($e, $event);
        }
    }

    /**
     * Report an exception thrown by an event listener.
     *
     * @param  \Throwable  $exception
     * @param  object  $event
     * @return void
     */
    protected function reportEventListenerException(Throwable $exception, object $event): void
    {
        try {
            $handler = app(ExceptionHandler::class);
            $handler->report($exception);
        } catch (Throwable $reportException) {
            // If reporting fails, silently ignore to ensure payment flow continues
            // This can happen in testing or when the exception handler is not available
        }
    }
}

