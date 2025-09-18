<?php

namespace Ideacrafters\EloquentPayable\Contracts;

use Ideacrafters\EloquentPayable\Models\Payment;

interface PaymentProcessor
{
    /**
     * Process a payment for the given payable item and payer.
     *
     * @param  mixed  $payable
     * @param  mixed  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    public function process($payable, $payer, float $amount, array $options = []): Payment;

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
}
