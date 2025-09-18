<?php

namespace Ideacrafters\EloquentPayable\Contracts;

use Ideacrafters\EloquentPayable\Models\Payment;

interface PaymentProcessor
{
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
     * Handle a webhook payload from the payment processor.
     *
     * @param  array  $payload
     * @return mixed
     */
    public function handleWebhook(array $payload);

    /**
     * Get the processor name.
     *
     * @return string
     */
    public function getName(): string;

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
