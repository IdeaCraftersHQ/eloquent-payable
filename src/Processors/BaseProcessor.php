<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Ideacrafters\EloquentPayable\Contracts\PaymentProcessor;
use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\Contracts\PaymentRedirect;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Models\PaymentRedirectModel;
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
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    abstract public function process(Payable $payable, Payer $payer, float $amount, array $options = []): Payment;

    /**
     * Create a redirect-based payment.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return PaymentRedirect
     */
    public function createRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): PaymentRedirect
    {
        throw new PaymentException('Redirect payments not supported by this processor.');
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
        throw new PaymentException('Redirect payments not supported by this processor.');
    }

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
     * Get the processor's supported features.
     *
     * @return array
     */
    public function getSupportedFeatures(): array
    {
        return [
            'immediate_payments' => $this->supportsImmediatePayments(),
            'redirect_payments' => $this->supportsRedirects(),
            'refunds' => true,
            'webhooks' => true,
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
        return true;
    }

    /**
     * Get the processor's configuration requirements.
     *
     * @return array
     */
    public function getConfigurationRequirements(): array
    {
        return [];
    }

    /**
     * Create a new payment record.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    protected function createPayment(Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        $paymentClass = Config::get('payable.models.payment');
        
        return $paymentClass::create([
            'payer_type' => $payer->getMorphClass(),
            'payer_id' => $payer->getKey(),
            'payable_type' => $payable->getMorphClass(),
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
     * @param  Payer  $payer
     * @return void
     * @throws PaymentException
     */
    protected function validatePayer(Payer $payer): void
    {
        if (!$payer->canMakePayments()) {
            throw new PaymentException('Payer is not authorized to make payments.');
        }
    }

    /**
     * Validate the payable item.
     *
     * @param  Payable  $payable
     * @return void
     * @throws PaymentException
     */
    protected function validatePayable(Payable $payable): void
    {
        if (!$payable->isPayableActive()) {
            throw new PaymentException('Payable item is not active.');
        }

        if (!$payable->requiresPayment()) {
            throw new PaymentException('Payable item does not require payment.');
        }
    }
}
