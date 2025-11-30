<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\Contracts\PaymentProcessor;
use Ideacrafters\EloquentPayable\Contracts\PaymentRedirect;
use Ideacrafters\EloquentPayable\Events\PaymentCreated;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\PaymentStatus;
use Ideacrafters\EloquentPayable\Traits\InteractsWithPaymentEvents;
use Illuminate\Support\Facades\Config;

abstract class BaseProcessor implements PaymentProcessor
{
    use InteractsWithPaymentEvents;

    /**
     * Get the processor name.
     */
    abstract public function getName(): string;

    /**
     * Process payment without firing events.
     * Used internally by process() and doCreateRedirect() implementations.
     */
    protected function processPaymentWithoutEvents(Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        $this->validatePayable($payable);
        $this->validatePayer($payer);
        $this->validateAmount($amount);
        $this->validateCurrency($options);

        // Create payment without firing event
        $payment = $this->createPayment($payable, $payer, $amount, $options);

        // Delegate to child implementation for processor-specific logic
        $payment = $this->doProcess($payment, $payable, $payer, $amount, $options);

        return $payment;
    }

    /**
     * Process a payment for the given payable item and payer.
     * Validates inputs, creates payment, delegates to doProcess(), then fires PaymentCreated event.
     */
    public function process(Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        $payment = null; // Initialize to null

        try {
            $payment = $this->processPaymentWithoutEvents($payable, $payer, $amount, $options);

            // Fire PaymentCreated event after all processor-specific updates are complete
            if ($this->shouldEmitEvents()) {
                event(new PaymentCreated($payment->fresh(), $this->isOffline()));
            }

            // If processor completes immediately, mark as paid (PaymentCompleted event is fired by markAsPaid())
            if ($this->completesImmediately()) {
                $payment->markAsPaid();
            }

            return $payment;
        } catch (\Exception $e) {
            // If payment was created but processing failed, mark as failed
            // If $payment is null, it means validation failed before payment creation
            if ($payment !== null) {
                $payment->markAsFailed($e->getMessage());
            }

            throw new PaymentException('Payment processing failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Protected abstract method for processing payment with processor-specific logic.
     * Child classes implement this to add processor-specific data (references, metadata, etc.).
     */
    abstract protected function doProcess(Payment $payment, Payable $payable, Payer $payer, float $amount, array $options = []): Payment;

    /**
     * Create a redirect-based payment.
     * Validates inputs and checks support before delegating to child implementation.
     */
    public function createRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): PaymentRedirect
    {
        if (! $this->supportsRedirects()) {
            throw new PaymentException('Redirect payments not supported by this processor.');
        }
        $this->validatePayable($payable);
        $this->validatePayer($payer);
        $this->validateAmount($amount);

        try {
            $result = $this->doCreateRedirect($payable, $payer, $amount, $options);

            $payment = $result['payment'];
            $redirect = $result['redirect'];

            // Fire PaymentCreated event after all processor-specific updates are complete
            if ($this->shouldEmitEvents()) {
                event(new PaymentCreated($payment->fresh(), $this->isOffline()));
            }

            return $redirect;
        } catch (\Exception $e) {
            // If payment was created but doCreateRedirect failed, mark as failed
            $payment = Payment::where('payable_type', $payable->getMorphClass())
                ->where('payable_id', $payable->getKey())
                ->where('payer_type', $payer->getMorphClass())
                ->where('payer_id', $payer->getKey())
                ->latest()
                ->first();

            if ($payment) {
                $payment->markAsFailed($e->getMessage());
            }

            throw new PaymentException('Redirect payment creation failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Complete a redirect-based payment.
     * Checks support before delegating to child implementation.
     */
    public function completeRedirect(Payment $payment, array $redirectData = []): Payment
    {
        if (! $this->supportsRedirects()) {
            throw new PaymentException('Redirect payments not supported by this processor.');
        }

        return $this->doCompleteRedirect($payment, $redirectData);
    }

    /**
     * Refund a payment.
     * Checks support before delegating to child implementation.
     */
    public function refund(Payment $payment, ?float $amount = null): Payment
    {
        if (! $this->supportsRefunds()) {
            throw new PaymentException('Refunds not supported by this processor.');
        }

        return $this->doRefund($payment, $amount);
    }

    /**
     * Cancel a payment.
     * Checks support before delegating to child implementation.
     */
    public function cancel(Payment $payment, ?string $reason = null): Payment
    {
        if (! $this->supportsCancellation()) {
            throw new PaymentException('Payment cancellation not supported by this processor.');
        }

        return $this->doCancel($payment, $reason);
    }

    /**
     * Protected abstract method for creating redirect-based payment.
     * Child classes implement this with actual logic.
     *
     * @return array{payment: Payment, redirect: PaymentRedirect}
     */
    abstract protected function doCreateRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): array;

    /**
     * Protected abstract method for completing redirect-based payment.
     * Child classes implement this with actual logic.
     */
    abstract protected function doCompleteRedirect(Payment $payment, array $redirectData = []): Payment;

    /**
     * Protected abstract method for refunding a payment.
     * Child classes implement this with actual logic.
     */
    abstract protected function doRefund(Payment $payment, ?float $amount = null): Payment;

    /**
     * Protected abstract method for canceling a payment.
     * Child classes implement this with actual logic.
     */
    abstract protected function doCancel(Payment $payment, ?string $reason = null): Payment;

    /**
     * Handle a webhook payload from the payment processor.
     *
     * @return mixed
     */
    public function handleWebhook(array $payload)
    {
        // Default implementation - override in specific processors
        return null;
    }

    /**
     * Check if the processor supports redirect-based payments.
     */
    abstract public function supportsRedirects(): bool;

    /**
     * Check if the processor supports immediate payments.
     */
    abstract public function supportsImmediatePayments(): bool;

    /**
     * Check if the processor supports payment cancellation.
     */
    abstract public function supportsCancellation(): bool;

    /**
     * Check if the processor supports refunds.
     */
    abstract public function supportsRefunds(): bool;

    /**
     * Check if this is an offline processor.
     */
    abstract public function isOffline(): bool;

    /**
     * Check if the processor completes payments immediately after creation.
     * When true, the payment will be marked as paid and PaymentCompleted event
     * will be fired right after PaymentCreated event in the process() method.
     */
    public function completesImmediately(): bool
    {
        return false; // Most processors require external confirmation
    }

    /**
     * Get the processor's supported features.
     */
    public function getSupportedFeatures(): array
    {
        return [
            'immediate_payments' => $this->supportsImmediatePayments(),
            'redirect_payments' => $this->supportsRedirects(),
            'refunds' => $this->supportsRefunds(),
            'cancellation' => $this->supportsCancellation(),
            'webhooks' => true,
        ];
    }

    /**
     * Validate payment options for this processor.
     */
    public function validateOptions(array $options): bool
    {
        return true;
    }

    /**
     * Get the processor's configuration requirements.
     */
    public function getConfigurationRequirements(): array
    {
        return [];
    }

    /**
     * Get the default currency for this processor.
     * Returns the global currency config by default, but can be overridden
     * by processors that require a specific currency (e.g., Slickpay uses DZD).
     *
     * @return string
     */
    public function getCurrency(): string
    {
        return Config::get('payable.currency', 'USD');
    }

    /**
     * Check if the processor supports multiple currencies.
     * Defaults to false - processors only support their default currency.
     *
     * @return bool
     */
    public function supportsMultipleCurrencies(): bool
    {
        return false;
    }

    /**
     * Get the processor name for event configuration checking.
     */
    protected function getProcessorNameForEvents(): ?string
    {
        return $this->getName();
    }

    /**
     * Create a new payment record.
     */
    protected function createPayment(Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        $paymentClass = Config::get('payable.models.payment');
        $payment = $paymentClass::create([
            'payer_type' => $payer->getMorphClass(),
            'payer_id' => $payer->getKey(),
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'amount' => $amount,
            'currency' => $options['currency'] ?? $this->getCurrency(),
            'status' => PaymentStatus::pending(),
            'processor' => $this->getName(),
            'reference' => $options['reference'] ?? null,
            'metadata' => $options['metadata'] ?? null,
            'notes' => $options['notes'] ?? null,
        ]);

        return $payment;
    }

    /**
     * Validate the payment amount.
     *
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
     * @throws PaymentException
     */
    protected function validatePayer(Payer $payer): void
    {
        if (! $payer->canMakePayments()) {
            throw new PaymentException('Payer is not authorized to make payments.');
        }
    }

    /**
     * Validate the payable item.
     *
     * @throws PaymentException
     */
    protected function validatePayable(Payable $payable): void
    {
        if (! $payable->isPayableActive()) {
            throw new PaymentException('Payable item is not active.');
        }

        if (! $payable->requiresPayment()) {
            throw new PaymentException('Payable item does not require payment.');
        }
    }

    /**
     * Validate the currency for this processor.
     * If the processor doesn't support multiple currencies and a different
     * currency is provided, throws an exception.
     *
     * @param  array  $options
     * @return void
     * @throws PaymentException
     */
    protected function validateCurrency(array $options): void
    {
        // If no currency is specified, validation passes (will use processor default)
        if (!isset($options['currency'])) {
            return;
        }

        $requestedCurrency = strtoupper($options['currency']);
        $processorCurrency = strtoupper($this->getCurrency());

        // If processor supports multiple currencies, any currency is allowed
        if ($this->supportsMultipleCurrencies()) {
            return;
        }

        // If processor doesn't support multiple currencies, only allow its default currency
        if ($requestedCurrency !== $processorCurrency) {
            throw new PaymentException(
                "Processor '{$this->getName()}' only supports currency '{$processorCurrency}'. " .
                "Currency '{$requestedCurrency}' is not supported. " .
                "Either remove the currency option to use the default, or use a processor that supports multiple currencies."
            );
        }
    }
}
