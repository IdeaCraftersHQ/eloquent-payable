<?php

namespace Ideacrafters\EloquentPayable\Contracts;

use Ideacrafters\EloquentPayable\Models\Payment;

interface PaymentProcessor
{
    /*
    |--------------------------------------------------------------------------
    | Core Payment Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Process a payment for the given payable item and payer.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    public function process(Payable $payable, Payer $payer, float $amount, array $options = []): Payment;

    /**
     * Create a redirect-based payment (for processors like Stripe Checkout).
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return PaymentRedirect
     */
    public function createRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): PaymentRedirect;

    /**
     * Complete a redirect-based payment.
     *
     * @param  Payment  $payment
     * @param  array  $redirectData
     * @return Payment
     */
    public function completeRedirect(Payment $payment, array $redirectData = []): Payment;

    /**
     * Refund a payment.
     *
     * @param  Payment  $payment
     * @param  float|null  $amount
     * @return Payment
     */
    public function refund(Payment $payment, ?float $amount = null): Payment;

    /**
     * Cancel a payment.
     *
     * @param  Payment  $payment
     * @param  string|null  $reason
     * @return Payment
     */
    public function cancel(Payment $payment, ?string $reason = null): Payment;

    /**
     * Handle a webhook payload from the payment processor.
     *
     * @param  array  $payload
     * @return mixed
     */
    public function handleWebhook(array $payload);

    /*
    |--------------------------------------------------------------------------
    | Processor Identity
    |--------------------------------------------------------------------------
    */

    /**
     * Get the processor name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the default currency for this processor.
     * Returns the global currency config by default, but can be overridden
     * by processors that require a specific currency (e.g., Slickpay uses DZD).
     *
     * @return string
     */
    public function getCurrency(): string;

    /*
    |--------------------------------------------------------------------------
    | Feature Support Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if the processor supports redirect-based payments.
     *
     * @return bool
     */
    public function supportsRedirects(): bool;

    /**
     * Check if the processor supports immediate payments.
     *
     * @return bool
     */
    public function supportsImmediatePayments(): bool;

    /**
     * Check if the processor supports payment cancellation.
     *
     * @return bool
     */
    public function supportsCancellation(): bool;

    /**
     * Check if the processor supports refunds.
     *
     * @return bool
     */
    public function supportsRefunds(): bool;

    /**
     * Check if the processor supports multiple currencies.
     * When false, the processor only supports its default currency.
     * When true, the processor can accept payments in different currencies.
     *
     * @return bool
     */
    public function supportsMultipleCurrencies(): bool;

    /**
     * Check if this is an offline processor.
     *
     * @return bool
     */
    public function isOffline(): bool;

    /**
     * Check if the processor completes payments immediately after creation.
     * When true, the payment will be marked as paid and PaymentCompleted event
     * will be fired right after PaymentCreated event in the process() method.
     *
     * @return bool
     */
    public function completesImmediately(): bool;

    /*
    |--------------------------------------------------------------------------
    | Configuration & Metadata
    |--------------------------------------------------------------------------
    */

    /**
     * Get the processor's supported features.
     *
     * @return array
     */
    public function getSupportedFeatures(): array;

    /**
     * Validate payment options for this processor.
     *
     * @param  array  $options
     * @return bool
     */
    public function validateOptions(array $options): bool;

    /**
     * Get the processor's configuration requirements.
     *
     * @return array
     */
    public function getConfigurationRequirements(): array;
}
