<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Carbon\Carbon;
use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\Contracts\PaymentRedirect;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Models\PaymentRedirectModel;
use Ideacrafters\EloquentPayable\PaymentStatus;
use Illuminate\Support\Facades\Config;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;

class StripeProcessor extends BaseProcessor
{
    /**
     * The webhook handler instance.
     *
     * @var StripeWebhookHandler
     */
    protected $webhookHandler;

    /*
    |--------------------------------------------------------------------------
    | Constructor & Initialization
    |--------------------------------------------------------------------------
    */

    /**
     * Initialize the Stripe processor.
     */
    public function __construct()
    {
        $this->initializeStripe();
        $this->webhookHandler = $this->createWebhookHandler();
    }

    /*
    |--------------------------------------------------------------------------
    | Core Payment Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Process a payment with Stripe-specific logic.
     */
    protected function doProcess(Payment $payment, Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        if (isset($options['payment_method_id'])) {
            // Immediate payment with payment method
            $this->processImmediatePayment($payment, $options);
        } else {
            // Create payment intent for later confirmation
            $this->createPaymentIntent($payment, $options);
        }

        return $payment;
    }

    /**
     * Create a redirect-based payment using Stripe Checkout.
     */
    protected function doCreateRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): array
    {
        $payment = $this->createPayment($payable, $payer, $amount, $options);

        $session = Session::create([
            'payment_method_types' => $options['payment_method_types'] ?? ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($payment->currency),
                    'product_data' => [
                        'name' => $payable->getPayableTitle(),
                        'description' => $payable->getPayableDescription(),
                    ],
                    'unit_amount' => $this->convertToCents($amount),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $options['success_url'] ?? $this->getSuccessUrl($payment),
            'cancel_url' => $options['cancel_url'] ?? $this->getCancelUrl($payment),
            'client_reference_id' => $payment->id,
            'customer_email' => $payer->getEmail(),
            'metadata' => array_merge($payment->metadata ?? [], [
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->getKey(),
                'payer_type' => $payer->getMorphClass(),
                'payer_id' => $payer->getKey(),
            ]),
        ]);

        $payment->update([
            'reference' => $session->id,
            'status' => PaymentStatus::processing(),
            'metadata' => array_merge($payment->metadata ?? [], [
                'stripe_session_id' => $session->id,
                'stripe_payment_intent_id' => $session->payment_intent,
            ]),
        ]);

        return [
            'payment' => $payment,
            'redirect' => new PaymentRedirectModel(
                redirectUrl: $session->url,
                successUrl: $options['success_url'] ?? $this->getSuccessUrl($payment),
                cancelUrl: $options['cancel_url'] ?? $this->getCancelUrl($payment),
                failureUrl: $options['failure_url'] ?? $this->getFailureUrl($payment),
                redirectMethod: 'GET',
                redirectData: [],
                redirectSessionId: $session->id,
                redirectExpiresAt: Carbon::createFromTimestamp($session->expires_at),
                redirectMetadata: [
                    'stripe_session_id' => $session->id,
                    'stripe_payment_intent_id' => $session->payment_intent,
                ]
            ),
        ];
    }

    /**
     * Complete a redirect-based payment.
     */
    protected function doCompleteRedirect(Payment $payment, array $redirectData = []): Payment
    {
        if (! $payment->reference) {
            throw new PaymentException('Cannot complete redirect payment without Stripe session ID.');
        }

        try {
            $session = Session::retrieve($payment->reference);

            if ($session->payment_status === 'paid') {
                $payment->markAsPaid();
            } elseif ($session->payment_status === 'unpaid') {
                $payment->markAsFailed('Payment was not completed');
            }

            return $payment;

        } catch (\Exception $e) {
            $payment->markAsFailed('Failed to complete redirect payment: '.$e->getMessage());
            throw new PaymentException('Redirect payment completion failed: '.$e->getMessage(), 0, $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Processor Identity
    |--------------------------------------------------------------------------
    */

    /**
     * Get the processor name.
     */
    public function getName(): string
    {
        return ProcessorNames::STRIPE;
    }

    /*
    |--------------------------------------------------------------------------
    | Feature Support Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if the processor supports redirect-based payments.
     */
    public function supportsRedirects(): bool
    {
        return true;
    }

    /**
     * Check if the processor supports immediate payments.
     */
    public function supportsImmediatePayments(): bool
    {
        return true;
    }

    /**
     * Check if the processor supports payment cancellation.
     */
    public function supportsCancellation(): bool
    {
        return true;
    }

    /**
     * Check if the processor supports refunds.
     */
    public function supportsRefunds(): bool
    {
        return true;
    }

    /**
     * Check if the processor supports multiple currencies.
     * Stripe supports multiple currencies, so this returns true.
     *
     * @return bool
     */
    public function supportsMultipleCurrencies(): bool
    {
        return true;
    }

    /**
     * Check if this is an offline processor.
     */
    public function isOffline(): bool
    {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration & Metadata
    |--------------------------------------------------------------------------
    */

    /**
     * Get the processor's supported features.
     */
    public function getSupportedFeatures(): array
    {
        return array_merge(parent::getSupportedFeatures(), [
            'checkout_sessions' => true,
            'payment_intents' => true,
        ]);
    }

    /**
     * Validate payment options for this processor.
     */
    public function validateOptions(array $options): bool
    {
        // For immediate payments, require payment_method_id
        if (isset($options['payment_method_id'])) {
            return ! empty($options['payment_method_id']);
        }

        // For redirect payments, validate URLs
        if (isset($options['success_url']) || isset($options['cancel_url'])) {
            return true;
        }

        return true;
    }

    /**
     * Get the processor's configuration requirements.
     */
    public function getConfigurationRequirements(): array
    {
        return [
            'stripe_key' => 'Stripe publishable key',
            'stripe_secret' => 'Stripe secret key',
            'stripe_webhook_secret' => 'Stripe webhook secret (optional)',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Webhook Handling
    |--------------------------------------------------------------------------
    */

    /**
     * Handle a webhook payload from Stripe.
     *
     * @param  array  $payload
     * @return mixed
     */
    public function handleWebhook(array $payload)
    {
        return $this->webhookHandler->handle($payload);
    }

    /*
    |--------------------------------------------------------------------------
    | Protected Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Process an immediate payment with a payment method.
     */
    protected function processImmediatePayment(Payment $payment, array $options): void
    {
        $paymentIntent = PaymentIntent::create([
            'amount' => $this->convertToCents($payment->amount),
            'currency' => strtolower($payment->currency),
            'payment_method' => $options['payment_method_id'],
            'confirmation_method' => 'manual',
            'confirm' => true,
            'metadata' => array_merge($payment->metadata ?? [], [
                'payable_type' => $payment->payable_type,
                'payable_id' => $payment->payable_id,
                'payer_type' => $payment->payer_type,
                'payer_id' => $payment->payer_id,
            ]),
        ]);

        $payment->update([
            'reference' => $paymentIntent->id,
            'metadata' => array_merge($payment->metadata ?? [], [
                'stripe_payment_intent_id' => $paymentIntent->id,
                'stripe_client_secret' => $paymentIntent->client_secret,
            ]),
        ]);

        // Map Stripe payment intent statuses to our payment statuses
        // Note: succeeded and canceled are handled by webhooks
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
                // Other statuses (succeeded, canceled, payment_failed, etc.) are handled by webhooks
                // Do nothing - let webhook handle the final status
                break;
        }
    }

    /**
     * Create a payment intent for later confirmation.
     */
    protected function createPaymentIntent(Payment $payment, array $options): void
    {
        $paymentIntent = PaymentIntent::create([
            'amount' => $this->convertToCents($payment->amount),
            'currency' => strtolower($payment->currency),
            'metadata' => array_merge($payment->metadata ?? [], [
                'payable_type' => $payment->payable_type,
                'payable_id' => $payment->payable_id,
                'payer_type' => $payment->payer_type,
                'payer_id' => $payment->payer_id,
            ]),
        ]);

        $payment->update([
            'reference' => $paymentIntent->id,
            'status' => PaymentStatus::processing(),
            'metadata' => array_merge($payment->metadata ?? [], [
                'stripe_payment_intent_id' => $paymentIntent->id,
                'stripe_client_secret' => $paymentIntent->client_secret,
            ]),
        ]);
    }

    /**
     * Cancel a payment.
     * Only handles payment intent cancellation. Checkout sessions are handled by Stripe automatically.
     * When a checkout session is canceled (expiration, user action, etc.), Stripe handles it.
     * If a session was paid, we would have been notified via webhook already.
     */
    protected function doCancel(Payment $payment, ?string $reason = null): Payment
    {
        if (! $payment->reference) {
            throw new PaymentException('Cannot cancel payment without Stripe reference.');
        }

        // Check if payment is already canceled to avoid duplicate operations
        if ($payment->isCanceled()) {
            return $payment; // Already canceled, return early
        }

        try {
            // Only handle payment intent cancellation
            // Checkout sessions are canceled automatically by Stripe (expiration, user action, etc.)
            $paymentIntent = PaymentIntent::retrieve($payment->reference);

            // If cancellable, cancel via Stripe API first
            if (in_array($paymentIntent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing'])) {
                // Cancel the payment intent - this will trigger a payment_intent.canceled webhook
                $paymentIntent->cancel();
            }
            // If already canceled in Stripe, it should have been synced via webhook
            // If not synced, markAsCanceled() will throw (already canceled) or sync it (not canceled yet)
            // If succeeded, markAsCanceled() will throw (cannot cancel completed payments)

            // Mark as canceled - will throw if already canceled or completed
            $payment->markAsCanceled($reason);

            return $payment;
        } catch (\Exception $e) {
            throw new PaymentException('Payment cancellation failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Refund a payment.
     */
    protected function doRefund(Payment $payment, ?float $amount = null): Payment
    {
        if (! $payment->reference) {
            throw new PaymentException('Cannot refund payment without Stripe reference.');
        }

        $refundAmount = $amount ?? $payment->getRefundableAmount();

        if ($refundAmount <= 0) {
            return $payment;
        }

        try {
            $refund = Refund::create([
                'payment_intent' => $payment->reference,
                'amount' => $this->convertToCents($refundAmount),
                'metadata' => [
                    'payment_id' => $payment->id,
                    'payable_type' => $payment->payable_type,
                    'payable_id' => $payment->payable_id,
                ],
            ]);

            $newRefundedAmount = ($payment->refunded_amount ?? 0) + $refundAmount;
            $totalRefunded = $newRefundedAmount;
            $totalAmount = $payment->amount;

            $status = $totalRefunded >= $totalAmount
                ? PaymentStatus::refunded()
                : PaymentStatus::partiallyRefunded();

            $payment->update([
                'refunded_amount' => $newRefundedAmount,
                'status' => $status,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'stripe_refund_id' => $refund->id,
                ]),
            ]);

        } catch (\Exception $e) {
            throw new PaymentException('Refund failed: '.$e->getMessage(), 0, $e);
        }

        return $payment;
    }

    /**
     * Create the webhook handler instance.
     * Resolves from service container, allowing users to bind custom handlers.
     *
     * @return StripeWebhookHandler
     */
    protected function createWebhookHandler(): StripeWebhookHandler
    {
        return app(StripeWebhookHandler::class);
    }

    /**
     * Initialize Stripe with configuration.
     */
    protected function initializeStripe(): void
    {
        Stripe::setApiKey(Config::get('payable.stripe.secret'));
        Stripe::setApiVersion(Config::get('payable.stripe.api_version', '2020-08-27'));
    }

    /**
     * Convert amount to cents for Stripe.
     */
    protected function convertToCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Get the success URL for a payment.
     */
    protected function getSuccessUrl(Payment $payment): string
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');

        return url("{$prefix}/callback/success?payment={$payment->id}");
    }

    /**
     * Get the cancel URL for a payment.
     */
    protected function getCancelUrl(Payment $payment): string
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');

        return url("{$prefix}/callback/cancel?payment={$payment->id}");
    }

    /**
     * Get the failure URL for a payment.
     */
    protected function getFailureUrl(Payment $payment): string
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');

        return url("{$prefix}/callback/failed?payment={$payment->id}");
    }
}
