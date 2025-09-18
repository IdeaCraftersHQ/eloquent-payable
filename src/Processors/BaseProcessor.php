<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Ideacrafters\EloquentPayable\Contracts\PaymentProcessor;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Illuminate\Support\Facades\Config;

abstract class BaseProcessor implements PaymentProcessor
{
    /**
     * Get the processor name.
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Process a payment for the given payable item and payer.
     *
     * @param  mixed  $payable
     * @param  mixed  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    abstract public function process($payable, $payer, float $amount, array $options = []): Payment;

    /**
     * Refund a payment.
     *
     * @param  Payment  $payment
     * @param  float|null  $amount
     * @return Payment
     */
    abstract public function refund(Payment $payment, ?float $amount = null): Payment;

    /**
     * Handle a webhook payload from the payment processor.
     *
     * @param  array  $payload
     * @return mixed
     */
    public function handleWebhook(array $payload)
    {
        // Default implementation - override in specific processors
        return null;
    }

    /**
     * Create a new payment record.
     *
     * @param  mixed  $payable
     * @param  mixed  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    protected function createPayment($payable, $payer, float $amount, array $options = []): Payment
    {
        $paymentClass = Config::get('payable.models.payment');
        
        return $paymentClass::create([
            'payer_type' => get_class($payer),
            'payer_id' => $payer->getKey(),
            'payable_type' => get_class($payable),
            'payable_id' => $payable->getKey(),
            'amount' => $amount,
            'currency' => $options['currency'] ?? Config::get('payable.currency', 'USD'),
            'status' => Config::get('payable.statuses.pending', 'pending'),
            'processor' => $this->getName(),
            'reference' => $options['reference'] ?? null,
            'metadata' => $options['metadata'] ?? null,
            'notes' => $options['notes'] ?? null,
        ]);
    }

    /**
     * Validate the payment amount.
     *
     * @param  float  $amount
     * @return void
     * @throws PaymentException
     */
    protected function validateAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new PaymentException('Payment amount must be greater than zero.');
        }
    }

    /**
     * Validate the payer.
     *
     * @param  mixed  $payer
     * @return void
     * @throws PaymentException
     */
    protected function validatePayer($payer): void
    {
        if (!$payer || !method_exists($payer, 'getKey')) {
            throw new PaymentException('Invalid payer provided.');
        }
    }

    /**
     * Validate the payable item.
     *
     * @param  mixed  $payable
     * @return void
     * @throws PaymentException
     */
    protected function validatePayable($payable): void
    {
        if (!$payable || !method_exists($payable, 'getKey')) {
            throw new PaymentException('Invalid payable item provided.');
        }
    }
}
