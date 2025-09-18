<?php

namespace Ideacrafters\EloquentPayable\Traits;

use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Contracts\Payable as PayableContract;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\Contracts\PaymentRedirect;
use Ideacrafters\EloquentPayable\Events\PaymentCreated;
use Ideacrafters\EloquentPayable\Events\OfflinePaymentCreated;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

trait Payable
{
    /**
     * Get the payments for the payable item.
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * Get pending payments for the payable item.
     */
    public function pendingPayments(): MorphMany
    {
        return $this->payments()->pending();
    }

    /**
     * Get completed payments for the payable item.
     */
    public function completedPayments(): MorphMany
    {
        return $this->payments()->completed();
    }

    /**
     * Get failed payments for the payable item.
     */
    public function failedPayments(): MorphMany
    {
        return $this->payments()->failed();
    }

    /**
     * Process a payment for this payable item.
     *
     * @param  Payer  $payer
     * @param  float|null  $amount
     * @param  array  $options
     * @return Payment
     */
    public function pay(Payer $payer, ?float $amount = null, array $options = []): Payment
    {
        $amount = $amount ?? $this->getPayableAmount($payer);
        
        if (!$this->isPayableBy($payer)) {
            throw new PaymentException('This item is not payable by the given payer.');
        }

        $processor = $this->getPaymentProcessor($options);
        $payment = $processor->process($this, $payer, $amount, $options);

        Event::dispatch(new PaymentCreated($payment));

        return $payment;
    }

    /**
     * Create an offline payment for this payable item.
     *
     * @param  Payer  $payer
     * @param  float|null  $amount
     * @param  array  $options
     * @return Payment
     */
    public function payOffline(Payer $payer, ?float $amount = null, array $options = []): Payment
    {
        $amount = $amount ?? $this->getPayableAmount($payer);
        
        if (!$this->isPayableBy($payer)) {
            throw new PaymentException('This item is not payable by the given payer.');
        }

        $options['processor'] = 'offline';
        $processor = $this->getPaymentProcessor($options);
        $payment = $processor->process($this, $payer, $amount, $options);

        Event::dispatch(new OfflinePaymentCreated($payment));

        return $payment;
    }

    /**
     * Create a redirect-based payment for this payable item.
     *
     * @param  Payer  $payer
     * @param  float|null  $amount
     * @param  array  $options
     * @return PaymentRedirect
     */
    public function payRedirect(Payer $payer, ?float $amount = null, array $options = []): PaymentRedirect
    {
        $amount = $amount ?? $this->getPayableAmount($payer);
        
        if (!$this->isPayableBy($payer)) {
            throw new PaymentException('This item is not payable by the given payer.');
        }

        $processor = $this->getPaymentProcessor($options);
        
        if (!$processor->supportsRedirects()) {
            throw new PaymentException('Processor does not support redirect payments.');
        }
        
        return $processor->createRedirect($this, $payer, $amount, $options);
    }

    /**
     * Get the payable amount for the given payer.
     *
     * @param  Payer|null  $payer
     * @return float
     */
    public function getPayableAmount(?Payer $payer = null): float
    {
        // Default implementation - override in your model
        if (method_exists($this, 'getAmount')) {
            return $this->getAmount();
        }

        if (property_exists($this, 'amount')) {
            return (float) $this->amount;
        }

        if (property_exists($this, 'price')) {
            return (float) $this->price;
        }

        if (property_exists($this, 'total')) {
            return (float) $this->total;
        }

        throw new PaymentException('Unable to determine payable amount. Implement getPayableAmount() method or add amount/price/total property.');
    }

    /**
     * Check if the item is payable by the given payer.
     *
     * @param  Payer  $payer
     * @return bool
     */
    public function isPayableBy(Payer $payer): bool
    {
        // Default implementation - always payable
        // Override in your model for custom logic
        return true;
    }

    /**
     * Process a refund for a payment.
     *
     * @param  Payment  $payment
     * @param  float|null  $amount
     * @return Payment
     */
    public function refund(Payment $payment, ?float $amount = null): Payment
    {
        if ($payment->payable_id !== $this->getKey() || $payment->payable_type !== get_class($this)) {
            throw new PaymentException('Payment does not belong to this payable item.');
        }

        $processor = $payment->getProcessor();
        $refundedPayment = $processor->refund($payment, $amount);

        return $refundedPayment;
    }

    /**
     * Get payment URLs for callbacks and webhooks.
     *
     * @param  Payment  $payment
     * @return array
     */
    public function getPaymentUrls(Payment $payment): array
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');
        
        return [
            'success' => URL::to("{$prefix}/callback/success?payment={$payment->id}"),
            'cancel' => URL::to("{$prefix}/callback/cancel?payment={$payment->id}"),
            'failed' => URL::to("{$prefix}/callback/failed?payment={$payment->id}"),
        ];
    }

    /**
     * Get the payment processor instance.
     *
     * @param  array  $options
     * @return \Ideacrafters\EloquentPayable\Contracts\PaymentProcessor
     */
    protected function getPaymentProcessor(array $options = [])
    {
        $processorName = $options['processor'] ?? Config::get('payable.default_processor', 'stripe');
        $processors = Config::get('payable.processors', []);
        
        if (!isset($processors[$processorName])) {
            throw new PaymentException("Unknown payment processor: {$processorName}");
        }

        return app($processors[$processorName]);
    }

    /**
     * Boot the payable trait.
     *
     * @return void
     */
    public static function bootPayable()
    {
        // Ensure the model implements the Payable contract
        if (!in_array(PayableContract::class, class_implements(static::class))) {
            throw new PaymentException(
                'Models using the Payable trait must implement the Payable contract.'
            );
        }
    }
}
