<?php

namespace Ideacrafters\EloquentPayable\Traits;

use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasPayments
{
    /**
     * Get the payments made by this payer.
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payer');
    }

    /**
     * Get completed payments made by this payer.
     */
    public function completedPayments(): MorphMany
    {
        return $this->payments()->completed();
    }

    /**
     * Get pending payments made by this payer.
     */
    public function pendingPayments(): MorphMany
    {
        return $this->payments()->pending();
    }

    /**
     * Get failed payments made by this payer.
     */
    public function failedPayments(): MorphMany
    {
        return $this->payments()->failed();
    }

    /**
     * Get offline payments made by this payer.
     */
    public function offlinePayments(): MorphMany
    {
        return $this->payments()->offline();
    }

    /**
     * Get payments made today by this payer.
     */
    public function paymentsToday(): MorphMany
    {
        return $this->payments()->today();
    }

    /**
     * Get payments made this month by this payer.
     */
    public function paymentsThisMonth(): MorphMany
    {
        return $this->payments()->thisMonth();
    }

    /**
     * Get the payment for a specific payable item.
     *
     * @param  mixed  $payable
     * @return Payment|null
     */
    public function paymentFor($payable): ?Payment
    {
        return $this->payments()
            ->where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->latest()
            ->first();
    }

    /**
     * Get the latest payment for a specific payable item.
     *
     * @param  mixed  $payable
     * @return Payment|null
     */
    public function latestPaymentFor($payable): ?Payment
    {
        return $this->paymentFor($payable);
    }

    /**
     * Get all completed payments for a specific payable item.
     *
     * @param  mixed  $payable
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function completedPaymentsFor($payable)
    {
        return $this->payments()
            ->where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->completed()
            ->get();
    }

    /**
     * Get the total amount paid by this payer.
     *
     * @return float
     */
    public function getTotalPaid(): float
    {
        return $this->completedPayments()->sum('amount');
    }

    /**
     * Get the total amount paid this month by this payer.
     *
     * @return float
     */
    public function getTotalPaidThisMonth(): float
    {
        return $this->paymentsThisMonth()->completed()->sum('amount');
    }

    /**
     * Check if this payer has paid for a specific payable item.
     *
     * @param  mixed  $payable
     * @return bool
     */
    public function hasPaidFor($payable): bool
    {
        return $this->completedPaymentsFor($payable)->isNotEmpty();
    }

    /**
     * Check if this payer has a pending payment for a specific payable item.
     *
     * @param  mixed  $payable
     * @return bool
     */
    public function hasPendingPaymentFor($payable): bool
    {
        return $this->payments()
            ->where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->pending()
            ->exists();
    }
}
