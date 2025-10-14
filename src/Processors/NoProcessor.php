<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Processors\BaseProcessor;

class NoProcessor extends BaseProcessor
{
    /**
     * Get the processor name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'none';
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
        
        // For free items, immediately mark as completed
        $payment->markAsPaid();

        return $payment;
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
        // For free items, refunds are not applicable
        return $payment;
    }
}
