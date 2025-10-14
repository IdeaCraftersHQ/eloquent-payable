<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;
use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payer;

class OfflineProcessor extends BaseProcessor
{
    /**
     * Get the processor name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'offline';
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

        $options = array_merge([
            'reference' => $this->generateReference(),
            'type' => $options['type'] ?? 'manual',
            'metadata' => array_merge($options['metadata'] ?? [], [
                'payment_type' => $options['type'] ?? 'manual',
                'created_at' => Carbon::now()->toISOString(),
            ]),
        ], $options);

        return $this->createPayment($payable, $payer, $amount, $options);
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
        $refundAmount = $amount ?? $payment->getRefundableAmount();
        
        if ($refundAmount <= 0) {
            return $payment;
        }

        $newRefundedAmount = ($payment->refunded_amount ?? 0) + $refundAmount;
        $totalRefunded = $newRefundedAmount;
        $totalAmount = $payment->amount;

        $status = $totalRefunded >= $totalAmount 
            ? Config::get('payable.statuses.refunded', 'refunded')
            : Config::get('payable.statuses.partially_refunded', 'partially_refunded');

        $payment->update([
            'refunded_amount' => $newRefundedAmount,
            'status' => $status,
        ]);

        return $payment;
    }

    /**
     * Generate a unique reference for the payment.
     *
     * @return string
     */
    protected function generateReference(): string
    {
        return 'OFFLINE-' . strtoupper(Str::random(8));
    }
}
