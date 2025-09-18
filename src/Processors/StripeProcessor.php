<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Illuminate\Support\Facades\Config;

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
     * @param  mixed  $payable
     * @param  mixed  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    public function process($payable, $payer, float $amount, array $options = []): Payment
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
}
