<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;
use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\Contracts\PaymentRedirect;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Ideacrafters\EloquentPayable\PaymentStatus;

class OfflineProcessor extends BaseProcessor
{
    /*
    |--------------------------------------------------------------------------
    | Core Payment Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Process a payment for the given payable item and payer.
     * Override to set offline-specific options before calling parent.
     *
     * Note: Offline payments are NOT marked as paid immediately upon creation.
     * They require manual confirmation. When confirmed via markAsPaid(), the
     * PaymentCompleted event will be fired.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    public function process(Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        // Set offline-specific options before calling parent
        $options = array_merge([
            'reference' => $this->generateReference(),
            'type' => $options['type'] ?? 'manual',
            'metadata' => array_merge($options['metadata'] ?? [], [
                'payment_type' => $options['type'] ?? 'manual',
                'created_at' => Carbon::now()->toISOString(),
            ]),
        ], $options);

        return parent::process($payable, $payer, $amount, $options);
    }

    /**
     * Process a payment with offline-specific logic.
     *
     * @param  Payment  $payment
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    protected function doProcess(Payment $payment, Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        // Offline payments don't need additional processing after creation
        // The reference and metadata are already set via options before createPayment()
        return $payment;
    }

    /**
     * Create a redirect-based payment.
     * Not supported by offline processor.
     * This method should never be called as supportsRedirects() returns false.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return array{payment: Payment, redirect: PaymentRedirect}
     */
    protected function doCreateRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): array
    {
        throw new PaymentException('Redirect payments not supported by offline processor.');
    }

    /**
     * Complete a redirect-based payment.
     * Not supported by offline processor.
     * This method should never be called as supportsRedirects() returns false.
     *
     * @param  Payment  $payment
     * @param  array  $redirectData
     * @return Payment
     */
    protected function doCompleteRedirect(Payment $payment, array $redirectData = []): Payment
    {
        throw new PaymentException('Redirect payments not supported by offline processor.');
    }

    /**
     * Cancel a payment.
     *
     * @param  Payment  $payment
     * @param  string|null  $reason
     * @return Payment
     */
    protected function doCancel(Payment $payment, ?string $reason = null): Payment
    {
        // Offline payments can be canceled if they're still pending
        if ($payment->isCompleted()) {
            throw new PaymentException('Cannot cancel a completed offline payment.');
        }

        $payment->markAsCanceled($reason);

        return $payment;
    }

    /**
     * Refund a payment.
     *
     * @param  Payment  $payment
     * @param  float|null  $amount
     * @return Payment
     */
    protected function doRefund(Payment $payment, ?float $amount = null): Payment
    {
        $refundAmount = $amount ?? $payment->getRefundableAmount();
        
        if ($refundAmount <= 0) {
            return $payment;
        }

        $newRefundedAmount = ($payment->refunded_amount ?? 0) + $refundAmount;
        $totalRefunded = $newRefundedAmount;
        $totalAmount = $payment->amount;

        $status = $totalRefunded >= $totalAmount 
            ? PaymentStatus::refunded()
            : PaymentStatus::partiallyRefunded();

        $payment->update([
            'refunded_amount' => $newRefundedAmount,
            'status' => $status,
        ]);

        return $payment;
    }

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
    public function getName(): string
    {
        return ProcessorNames::OFFLINE;
    }

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
    public function supportsRedirects(): bool
    {
        return false;
    }

    /**
     * Check if the processor supports immediate payments.
     *
     * @return bool
     */
    public function supportsImmediatePayments(): bool
    {
        return true;
    }

    /**
     * Check if the processor supports payment cancellation.
     *
     * @return bool
     */
    public function supportsCancellation(): bool
    {
        return true;
    }

    /**
     * Check if the processor supports refunds.
     *
     * @return bool
     */
    public function supportsRefunds(): bool
    {
        return true;
    }

    /**
     * Check if this is an offline processor.
     *
     * @return bool
     */
    public function isOffline(): bool
    {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Protected Helper Methods
    |--------------------------------------------------------------------------
    */

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
