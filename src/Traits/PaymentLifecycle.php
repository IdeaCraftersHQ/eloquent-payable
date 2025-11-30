<?php

namespace Ideacrafters\EloquentPayable\Traits;

use Carbon\Carbon;
use Ideacrafters\EloquentPayable\Events\OfflinePaymentConfirmed;
use Ideacrafters\EloquentPayable\Events\PaymentCanceled;
use Ideacrafters\EloquentPayable\Events\PaymentCompleted;
use Ideacrafters\EloquentPayable\Events\PaymentFailed;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Ideacrafters\EloquentPayable\PaymentStatus;
use Ideacrafters\EloquentPayable\Processors\ProcessorNames;

trait PaymentLifecycle
{
    use InteractsWithPaymentEvents;

    /**
     * Boot the model with payment status and timestamp management.
     *
     * @return void
     */
    protected static function bootPaymentLifecycle()
    {
        static::updating(function ($payment) {
            // Manage timestamps based on status changes
            if ($payment->isDirty('status')) {
                $newStatus = $payment->status;

                switch ($newStatus) {
                    case PaymentStatus::completed():
                        // Status changed to completed -> set paid_at, clear others
                        $payment->paid_at = $payment->paid_at ?? Carbon::now();
                        $payment->failed_at = null;
                        $payment->canceled_at = null;
                        break;

                    case PaymentStatus::failed():
                        // Status changed to failed -> set failed_at, clear others
                        $payment->failed_at = $payment->failed_at ?? Carbon::now();
                        $payment->paid_at = null;
                        $payment->canceled_at = null;
                        break;

                    case PaymentStatus::canceled():
                        // Status changed to canceled -> set canceled_at, clear others
                        $payment->canceled_at = $payment->canceled_at ?? Carbon::now();
                        $payment->paid_at = null;
                        $payment->failed_at = null;
                        break;

                    case PaymentStatus::pending():
                    case PaymentStatus::processing():
                        // Status changed to pending or processing -> clear all timestamps
                        $payment->paid_at = null;
                        $payment->failed_at = null;
                        $payment->canceled_at = null;
                        break;
                }
            }
        });
    }

    /**
     * Mark the payment as paid.
     *
     * @param  \DateTimeInterface|string|null  $paidAt
     * @return $this
     */
    public function markAsPaid($paidAt = null)
    {
        // Skip if already completed (idempotent operation for backward compatibility)
        if ($this->isCompleted()) {
            return $this;
        }

        $this->update([
            'status' => PaymentStatus::completed(),
            'paid_at' => $paidAt ?? Carbon::now(),
            'failed_at' => null,
        ]);

        // Fire the payment completed event
        if ($this->shouldEmitEvents()) {
            event(new PaymentCompleted($this));

            // Fire legacy OfflinePaymentConfirmed for backward compatibility
            if ($this->isOffline()) {
                event(new OfflinePaymentConfirmed($this));
            }
        }

        return $this;
    }

    /**
     * Mark the payment as pending.
     *
     * @return $this
     */
    public function markAsPending()
    {
        $this->update([
            'status' => PaymentStatus::pending(),
        ]);

        return $this;
    }

    /**
     * Mark the payment as failed.
     *
     * @param  string|null  $reason
     * @return $this
     */
    public function markAsFailed($reason = null)
    {
        // Skip if already failed (idempotent operation for backward compatibility)
        if ($this->isFailed()) {
            return $this;
        }

        $this->update([
            'status' => PaymentStatus::failed(),
            'failed_at' => Carbon::now(),
            'paid_at' => null,
            'notes' => $reason ? ($this->notes ? $this->notes . "\n" . $reason : $reason) : $this->notes,
        ]);

        // Fire the payment failed event
        if ($this->shouldEmitEvents()) {
            event(new PaymentFailed($this));
        }

        return $this;
    }

    /**
     * Mark the payment as canceled.
     *
     * @param  string|null  $reason
     * @return $this
     */
    public function markAsCanceled($reason = null)
    {
        // Skip if already canceled (idempotent operation for backward compatibility)
        if ($this->isCanceled()) {
            return $this;
        }

        $this->update([
            'status' => PaymentStatus::canceled(),
            'canceled_at' => Carbon::now(),
            'paid_at' => null,
            'failed_at' => null,
            'notes' => $reason ? ($this->notes ? $this->notes . "\n" . $reason : $reason) : $this->notes,
        ]);

        if ($this->shouldEmitEvents()) {
            event(new PaymentCanceled($this));
        }

        return $this;
    }

    /**
     * Check if the payment is completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === PaymentStatus::completed();
    }

    /**
     * Check if the payment is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === PaymentStatus::pending();
    }

    /**
     * Check if the payment is failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::failed();
    }

    /**
     * Check if the payment is processing.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === PaymentStatus::processing();
    }

    /**
     * Check if the payment is refunded.
     *
     * @return bool
     */
    public function isRefunded(): bool
    {
        return in_array($this->status, [
            PaymentStatus::refunded(),
            PaymentStatus::partiallyRefunded(),
        ]);
    }

    /**
     * Check if the payment is canceled.
     *
     * @return bool
     */
    public function isCanceled(): bool
    {
        return $this->status === PaymentStatus::canceled();
    }

    /**
     * Scope a query to only include completed payments.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', PaymentStatus::completed());
    }

    /**
     * Scope a query to only include pending payments.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', PaymentStatus::pending());
    }

    /**
     * Scope a query to only include failed payments.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', PaymentStatus::failed());
    }

    /**
     * Scope a query to only include canceled payments.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCanceled($query)
    {
        return $query->where('status', PaymentStatus::canceled());
    }

    /**
     * Scope a query to only include offline payments.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOffline($query)
    {
        return $query->where('processor', ProcessorNames::OFFLINE);
    }

    /**
     * Scope a query to only include payments from today.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', Carbon::today());
    }

    /**
     * Scope a query to only include payments from this month.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeThisMonth($query)
    {
        return $query->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year);
    }

    /**
     * Get the processor name for event configuration checking.
     * 
     * Default implementation assumes the model has a 'processor' property.
     * Override this method if your model uses a different field or logic.
     *
     * @return string|null
     */
    protected function getProcessorNameForEvents(): ?string
    {
        return $this->processor ?? null;
    }
}

