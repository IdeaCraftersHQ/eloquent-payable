<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\PaymentStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookHandler
{

    /**
     * Handle a webhook payload from Stripe.
     *
     * @param  array  $payload
     * @return mixed
     */
    public function handle(array $payload)
    {
        $webhookSecret = Config::get('payable.stripe.webhook_secret');

        if (! $webhookSecret) {
            throw new PaymentException('Stripe webhook secret not configured.');
        }

        try {
            $event = Webhook::constructEvent(
                $payload['body'],
                $payload['signature'],
                $webhookSecret
            );
        } catch (SignatureVerificationException $e) {
            throw new PaymentException('Invalid webhook signature: '.$e->getMessage());
        }

        // Check if event has already been processed (idempotency check)
        if ($this->isEventAlreadyProcessed($event->id)) {
            Log::debug('Stripe webhook event already processed, skipping', [
                'event_id' => $event->id,
                'event_type' => $event->type,
            ]);
            return null;
        }

        // Resolve and invoke the event handler (throws exception if not found)
        $method = $this->resolveEventHandler($event->type);
        
        $result = $this->{$method}($event->data->object);
        
        // Mark event as processed after successful handling
        $this->markEventAsProcessed($event->id, $event->type);
        
        return $result;
    }

    /**
     * Resolve the event handler method name for a given event type.
     * Throws an exception if the handler method does not exist.
     *
     * @param  string  $eventType
     * @return string
     * @throws PaymentException
     */
    protected function resolveEventHandler(string $eventType): string
    {
        // Generate method name from event type: payment_intent.succeeded -> handlePaymentIntentSucceeded
        $method = 'handle'.Str::studly(str_replace('.', '_', $eventType));

        // Check if method exists
        if (method_exists($this, $method)) {
            return $method;
        }

        // Get the actual class name (in case user extended this class)
        $className = static::class;

        // Throw exception for unhandled events
        throw new PaymentException(
            "Stripe webhook event '{$eventType}' has no handler method. ".
            "Create a '{$method}()' method in the {$className} class to handle this event."
        );
    }

    /**
     * Check if a Stripe event has already been processed.
     *
     * @param  string  $eventId
     * @return bool
     */
    protected function isEventAlreadyProcessed(string $eventId): bool
    {
        $cacheKey = "stripe_webhook_processed:{$eventId}";
        return Cache::has($cacheKey);
    }

    /**
     * Mark a Stripe event as processed to prevent duplicate processing.
     *
     * @param  string  $eventId
     * @param  string  $eventType
     * @return void
     */
    protected function markEventAsProcessed(string $eventId, string $eventType): void
    {
        $cacheKey = "stripe_webhook_processed:{$eventId}";
        $ttlDays = Config::get('payable.webhooks.event_idempotency_ttl_days', 30);
        
        // Cache for the configured number of days (default 30 days)
        Cache::put($cacheKey, true, now()->addDays($ttlDays));
        
        Log::debug('Stripe webhook event marked as processed', [
            'event_id' => $eventId,
            'event_type' => $eventType,
        ]);
    }

    /**
     * Handle successful payment intent.
     * This is idempotent - if payment is already completed, it does nothing.
     * If payment is canceled, markAsPaid() will throw an exception.
     *
     * @param  \Stripe\PaymentIntent  $paymentIntent
     * @return void
     */
    protected function handlePaymentIntentSucceeded($paymentIntent): void
    {
        $payment = Payment::where('reference', $paymentIntent->id)->first();

        if (!$payment) {
            return;
        }

        // Idempotency check: if already completed, do nothing
        if ($payment->isCompleted()) {
            return;
        }

        // Note: In Stripe's business logic, a payment cannot succeed after being canceled.
        // Cancellation always happens before success. If a payment is canceled and we receive
        // a succeeded webhook, this indicates a data inconsistency/error condition.
        // markAsPaid() will throw an exception for canceled payments, which is the correct
        // behavior to surface this error rather than silently ignoring it.
        $payment->markAsPaid();
    }

    /**
     * Handle failed payment intent.
     * This is idempotent - if payment is already failed, it does nothing.
     *
     * @param  \Stripe\PaymentIntent  $paymentIntent
     * @return void
     */
    protected function handlePaymentIntentPaymentFailed($paymentIntent): void
    {
        $payment = Payment::where('reference', $paymentIntent->id)->first();

        if (!$payment) {
            return;
        }

        // Idempotency check: if already failed, do nothing
        if ($payment->isFailed()) {
            return;
        }

        $payment->markAsFailed('Payment failed: '.($paymentIntent->last_payment_error->message ?? 'Unknown error'));
    }

    /**
     * Handle canceled payment intent webhook.
     * This handles Stripe-initiated cancellations (not user-initiated via API).
     * This is idempotent - if payment is already canceled, it does nothing.
     * If payment is completed, markAsCanceled() will throw an exception.
     *
     * @param  \Stripe\PaymentIntent  $paymentIntent
     * @return void
     */
    protected function handlePaymentIntentCanceled($paymentIntent): void
    {
        $payment = Payment::where('reference', $paymentIntent->id)->first();

        if (!$payment) {
            return;
        }

        // Idempotency check: if already canceled, do nothing
        if ($payment->isCanceled()) {
            return;
        }

        // This is Stripe-initiated cancellation (via webhook), not user-initiated
        // So we don't have a user reason - use a generic message
        // If payment is completed, markAsCanceled() will throw an exception
        $payment->markAsCanceled('Payment canceled via Stripe webhook');
    }

    /**
     * Handle updated payment intent webhook.
     * Maps Stripe payment intent statuses to our payment statuses.
     * Note: succeeded and canceled are handled by their own webhook events.
     *
     * @param  \Stripe\PaymentIntent  $paymentIntent
     * @return void
     */
    protected function handlePaymentIntentUpdated($paymentIntent): void
    {
        $payment = Payment::where('reference', $paymentIntent->id)->first();

        if (! $payment) {
            return;
        }

        // Map Stripe payment intent statuses to our payment statuses
        switch ($paymentIntent->status) {
            case 'processing':
                $payment->update(['status' => PaymentStatus::processing()]);
                break;
            case 'requires_payment_method':
            case 'requires_confirmation':
            case 'requires_action':
            case 'requires_capture':
                // These are valid intermediate states - keep as pending
                $payment->update(['status' => PaymentStatus::pending()]);
                break;
            default:
                // Other statuses (succeeded, canceled, payment_failed, etc.) are handled by their own webhook events
                break;
        }
    }

    /**
     * Handle webhook events that don't have a specific handler method.
     * Throws an exception to alert developers that they're listening to events
     * they haven't defined handlers for.
     *
     * @param  \Stripe\Event  $event
     * @return mixed
     * @throws PaymentException
     */
    protected function missingMethod($event)
    {
        throw new PaymentException(
            "Stripe webhook event '{$event->type}' (ID: {$event->id}) has no handler method. ".
            "Create a 'handle".Str::studly(str_replace('.', '_', $event->type))."()' method to handle this event."
        );
    }
}

