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
     * Default: Resolve a human-friendly title.
     */
    public function getPayableTitle(): string
    {
        if (property_exists($this, 'title') && $this->title) {
            return (string) $this->title;
        }

        if (property_exists($this, 'name') && $this->name) {
            return (string) $this->name;
        }

        $classBase = class_basename(static::class);
        $id = method_exists($this, 'getKey') ? $this->getKey() : null;

        return $id ? "{$classBase} #{$id}" : $classBase;
    }

    /**
     * Default: Use model's description when present.
     */
    public function getPayableDescription(): ?string
    {
        return property_exists($this, 'description') && $this->description
            ? (string) $this->description
            : null;
    }

    /**
     * Default: Read currency from config.
     */
    public function getPayableCurrency(): string
    {
        return (string) Config::get('payable.currency', 'USD');
    }

    /**
     * Default: Use model metadata array when present.
     */
    public function getPayableMetadata(): array
    {
        return property_exists($this, 'metadata') && is_array($this->metadata)
            ? $this->metadata
            : [];
    }

    /**
     * Default: Use numeric tax property when present; otherwise 0.
     */
    public function getPayableTax(?Payer $payer = null): float
    {
        if (property_exists($this, 'tax') && is_numeric($this->tax)) {
            return (float) $this->tax;
        }

        return 0.0;
    }

    /**
     * Default: Use numeric discount property when present; otherwise 0.
     */
    public function getPayableDiscount(?Payer $payer = null): float
    {
        if (property_exists($this, 'discount') && is_numeric($this->discount)) {
            return (float) $this->discount;
        }

        return 0.0;
    }

    /**
     * Default: amount + tax - discount.
     */
    public function getPayableTotal(?Payer $payer = null): float
    {
        $amount = $this->getPayableAmount($payer);
        $tax = $this->getPayableTax($payer);
        $discount = $this->getPayableDiscount($payer);

        return max(0.0, (float) $amount + (float) $tax - (float) $discount);
    }

    /**
     * Default: requires payment unless marked free or amount resolves to 0.
     */
    public function requiresPayment(): bool
    {
        if (property_exists($this, 'is_free') && $this->is_free) {
            return false;
        }

        try {
            return $this->getPayableAmount(null) > 0.0;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Default: Use `due_date`/`expires_at` if instance of DateTimeInterface.
     */
    public function getPayableDueDate(): ?\DateTimeInterface
    {
        if (property_exists($this, 'due_date') && $this->due_date instanceof \DateTimeInterface) {
            return $this->due_date;
        }

        if (property_exists($this, 'expires_at') && $this->expires_at instanceof \DateTimeInterface) {
            return $this->expires_at;
        }

        return null;
    }

    /**
     * Default: Use model `status`/`state` or fallback to config pending status.
     */
    public function getPayableStatus(): string
    {
        if (property_exists($this, 'status') && $this->status) {
            return (string) $this->status;
        }

        if (property_exists($this, 'state') && $this->state) {
            return (string) $this->state;
        }

        return (string) (Config::get('payable.statuses.pending', 'pending'));
    }
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
