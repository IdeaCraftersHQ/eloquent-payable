<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Checkout\Session;
use Stripe\Refund;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\Contracts\PaymentRedirect;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Models\PaymentRedirectModel;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;

class StripeProcessor extends BaseProcessor
{
    /**
     * Initialize the Stripe processor.
     */
    public function __construct()
    {
        $this->initializeStripe();
    }

    /**
     * Initialize Stripe with configuration.
     *
     * @return void
     */
    protected function initializeStripe(): void
    {
        Stripe::setApiKey(Config::get('payable.stripe.secret'));
        Stripe::setApiVersion(Config::get('payable.stripe.api_version', '2020-08-27'));
    }

    /**
     * Get the processor name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'stripe';
    }

    /**
     * Process a payment for the given payable item and payer.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    public function process(Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        $this->validatePayable($payable);
        $this->validatePayer($payer);
        $this->validateAmount($amount);

        $payment = $this->createPayment($payable, $payer, $amount, $options);

        try {
            if (isset($options['payment_method_id'])) {
                // Immediate payment with payment method
                $this->processImmediatePayment($payment, $options);
            } else {
                // Create payment intent for later confirmation
                $this->createPaymentIntent($payment, $options);
            }
        } catch (\Exception $e) {
            $payment->markAsFailed($e->getMessage());
            throw new PaymentException('Payment processing failed: ' . $e->getMessage(), 0, $e);
        }

        return $payment;
    }

    /**
     * Create a redirect-based payment using Stripe Checkout.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return PaymentRedirect
     */
    public function createRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): PaymentRedirect
    {
        $this->validatePayable($payable);
        $this->validatePayer($payer);
        $this->validateAmount($amount);

        $payment = $this->createPayment($payable, $payer, $amount, $options);

        try {
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
                'status' => Config::get('payable.statuses.processing', 'processing'),
                'metadata' => array_merge($payment->metadata ?? [], [
                    'stripe_session_id' => $session->id,
                    'stripe_payment_intent_id' => $session->payment_intent,
                ]),
            ]);

            return new PaymentRedirectModel(
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
            );

        } catch (\Exception $e) {
            $payment->markAsFailed($e->getMessage());
            throw new PaymentException('Redirect payment creation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Complete a redirect-based payment.
     *
     * @param  Payment  $payment
     * @param  array  $redirectData
     * @return Payment
     */
    public function completeRedirect(Payment $payment, array $redirectData = []): Payment
    {
        if (!$payment->reference) {
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
            $payment->markAsFailed('Failed to complete redirect payment: ' . $e->getMessage());
            throw new PaymentException('Redirect payment completion failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if the processor supports redirect-based payments.
     *
     * @return bool
     */
    public function supportsRedirects(): bool
    {
        return true;
    }

    /**
     * Get the processor's supported features.
     *
     * @return array
     */
    public function getSupportedFeatures(): array
    {
        return [
            'immediate_payments' => true,
            'redirect_payments' => true,
            'refunds' => true,
            'webhooks' => true,
            'checkout_sessions' => true,
            'payment_intents' => true,
        ];
    }

    /**
     * Validate payment options for this processor.
     *
     * @param  array  $options
     * @return bool
     */
    public function validateOptions(array $options): bool
    {
        // For immediate payments, require payment_method_id
        if (isset($options['payment_method_id'])) {
            return !empty($options['payment_method_id']);
        }

        // For redirect payments, validate URLs
        if (isset($options['success_url']) || isset($options['cancel_url'])) {
            return true;
        }

        return true;
    }

    /**
     * Get the processor's configuration requirements.
     *
     * @return array
     */
    public function getConfigurationRequirements(): array
    {
        return [
            'stripe_key' => 'Stripe publishable key',
            'stripe_secret' => 'Stripe secret key',
            'stripe_webhook_secret' => 'Stripe webhook secret (optional)',
        ];
    }

    /**
     * Process an immediate payment with a payment method.
     *
     * @param  Payment  $payment
     * @param  array  $options
     * @return void
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

        if ($paymentIntent->status === 'succeeded') {
            $payment->markAsPaid();
        } elseif ($paymentIntent->status === 'requires_action') {
            $payment->update(['status' => Config::get('payable.statuses.processing', 'processing')]);
        } else {
            $payment->markAsFailed('Payment requires additional action');
        }
    }

    /**
     * Create a payment intent for later confirmation.
     *
     * @param  Payment  $payment
     * @param  array  $options
     * @return void
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
            'status' => Config::get('payable.statuses.processing', 'processing'),
            'metadata' => array_merge($payment->metadata ?? [], [
                'stripe_payment_intent_id' => $paymentIntent->id,
                'stripe_client_secret' => $paymentIntent->client_secret,
            ]),
        ]);
    }

    /**
     * Refund a payment.
     *
     * @param  Payment  $payment
     * @param  float|null  $amount
     * @return Payment
     */
    public function refund(Payment $payment, ?float $amount = null): Payment
    {
        if (!$payment->reference) {
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
            ? Config::get('payable.statuses.refunded', 'refunded')
            : Config::get('payable.statuses.partially_refunded', 'partially_refunded');

            $payment->update([
                'refunded_amount' => $newRefundedAmount,
                'status' => $status,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'stripe_refund_id' => $refund->id,
                ]),
            ]);

        } catch (\Exception $e) {
            throw new PaymentException('Refund failed: ' . $e->getMessage(), 0, $e);
        }

        return $payment;
    }

    /**
     * Handle a webhook payload from Stripe.
     *
     * @param  array  $payload
     * @return mixed
     */
    public function handleWebhook(array $payload)
    {
        $webhookSecret = Config::get('payable.stripe.webhook_secret');
        
        if (!$webhookSecret) {
            throw new PaymentException('Stripe webhook secret not configured.');
        }

        try {
            $event = Webhook::constructEvent(
                $payload['body'],
                $payload['signature'],
                $webhookSecret
            );
        } catch (SignatureVerificationException $e) {
            throw new PaymentException('Invalid webhook signature: ' . $e->getMessage());
        }

        switch ($event->type) {
            case 'payment_intent.succeeded':
                return $this->handlePaymentIntentSucceeded($event->data->object);
            case 'payment_intent.payment_failed':
                return $this->handlePaymentIntentFailed($event->data->object);
            default:
                return null;
        }
    }

    /**
     * Handle successful payment intent.
     *
     * @param  \Stripe\PaymentIntent  $paymentIntent
     * @return void
     */
    protected function handlePaymentIntentSucceeded($paymentIntent): void
    {
        $payment = Payment::where('reference', $paymentIntent->id)->first();
        
        if ($payment) {
            $payment->markAsPaid();
        }
    }

    /**
     * Handle failed payment intent.
     *
     * @param  \Stripe\PaymentIntent  $paymentIntent
     * @return void
     */
    protected function handlePaymentIntentFailed($paymentIntent): void
    {
        $payment = Payment::where('reference', $paymentIntent->id)->first();
        
        if ($payment) {
            $payment->markAsFailed('Payment failed: ' . ($paymentIntent->last_payment_error->message ?? 'Unknown error'));
        }
    }

    /**
     * Convert amount to cents for Stripe.
     *
     * @param  float  $amount
     * @return int
     */
    protected function convertToCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Get the success URL for a payment.
     *
     * @param  Payment  $payment
     * @return string
     */
    protected function getSuccessUrl(Payment $payment): string
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');
        return url("{$prefix}/callback/success?payment={$payment->id}");
    }

    /**
     * Get the cancel URL for a payment.
     *
     * @param  Payment  $payment
     * @return string
     */
    protected function getCancelUrl(Payment $payment): string
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');
        return url("{$prefix}/callback/cancel?payment={$payment->id}");
    }

    /**
     * Get the failure URL for a payment.
     *
     * @param  Payment  $payment
     * @return string
     */
    protected function getFailureUrl(Payment $payment): string
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');
        return url("{$prefix}/callback/failed?payment={$payment->id}");
    }
}
