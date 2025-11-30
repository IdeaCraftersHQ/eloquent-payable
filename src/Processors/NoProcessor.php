<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Contracts\PaymentRedirect;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
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
        return ProcessorNames::NONE;
    }

    /**
     * Process a payment with no processor-specific logic.
     * For free items, immediately mark as completed.
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
        // No processing needed for free items
        // Payment will be marked as completed in BaseProcessor::process() via completesImmediately() flag
        return $payment;
    }

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
        return false;
    }

    /**
     * Check if the processor supports refunds.
     *
     * @return bool
     */
    public function supportsRefunds(): bool
    {
        return false; // Free items don't need refunds
    }

    /**
     * Check if this is an offline processor.
     *
     * @return bool
     */
    public function isOffline(): bool
    {
        return false;
    }

    /**
     * Check if the processor completes payments immediately after creation.
     *
     * @return bool
     */
    public function completesImmediately(): bool
    {
        return true; // Free items are immediately completed
    }

    /**
     * Create a redirect-based payment.
     * Not supported by no processor.
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
        throw new \Ideacrafters\EloquentPayable\Exceptions\PaymentException('Redirect payments not supported by no processor.');
    }

    /**
     * Complete a redirect-based payment.
     * Not supported by no processor.
     * This method should never be called as supportsRedirects() returns false.
     *
     * @param  Payment  $payment
     * @param  array  $redirectData
     * @return Payment
     */
    protected function doCompleteRedirect(Payment $payment, array $redirectData = []): Payment
    {
        throw new \Ideacrafters\EloquentPayable\Exceptions\PaymentException('Redirect payments not supported by no processor.');
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
        // For free items, cancellation is not applicable since they're immediately completed
        // Free item payments are marked as paid during process(), so they cannot be canceled
        if ($payment->isCompleted()) {
            throw new PaymentException('Cannot cancel a completed free item payment.');
        }

        $payment->markAsCanceled($reason);

        return $payment;
    }

    /**
     * Refund a payment.
     * Not supported by no processor.
     * This method should never be called as supportsRefunds() returns false.
     *
     * @param  Payment  $payment
     * @param  float|null  $amount
     * @return Payment
     */
    protected function doRefund(Payment $payment, ?float $amount = null): Payment
    {
        // For free items, refunds are not applicable
        return $payment;
    }
}
