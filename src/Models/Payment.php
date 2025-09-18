<?php

namespace Ideacrafters\EloquentPayable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Ideacrafters\EloquentPayable\Contracts\PaymentProcessor;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\App;
use Carbon\Carbon;

class Payment extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'payer_type',
        'payer_id',
        'payable_type',
        'payable_id',
        'amount',
        'currency',
        'status',
        'processor',
        'reference',
        'metadata',
        'refunded_amount',
        'notes',
        'paid_at',
        'failed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'metadata' => 'array',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Create a new Eloquent model instance.
     *
     * @param  array  $attributes
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        $this->table = Config::get('payable.tables.payments', 'payments');
        parent::__construct($attributes);
    }

    /**
     * Get the payer that owns the payment.
     */
    public function payer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the payable item that owns the payment.
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the processor instance for this payment.
     *
     * @return PaymentProcessor
     */
    public function getProcessor(): PaymentProcessor
    {
        $processors = Config::get('payable.processors', []);
        $processorClass = $processors[$this->processor] ?? null;

        if (!$processorClass) {
            throw new PaymentException("Unknown processor: {$this->processor}");
        }

        return App::make($processorClass);
    }

    /**
     * Mark the payment as paid.
     *
     * @param  \DateTimeInterface|string|null  $paidAt
     * @return $this
     */
    public function markAsPaid($paidAt = null)
    {
        $this->update([
            'status' => Config::get('payable.statuses.completed', 'completed'),
            'paid_at' => $paidAt ?? Carbon::now(),
            'failed_at' => null,
        ]);

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
            'status' => Config::get('payable.statuses.pending', 'pending'),
            'paid_at' => null,
            'failed_at' => null,
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
        $this->update([
            'status' => Config::get('payable.statuses.failed', 'failed'),
            'failed_at' => Carbon::now(),
            'paid_at' => null,
            'notes' => $reason ? ($this->notes ? $this->notes . "\n" . $reason : $reason) : $this->notes,
        ]);

        return $this;
    }

    /**
     * Check if the payment is offline.
     *
     * @return bool
     */
    public function isOffline(): bool
    {
        return $this->processor === 'offline';
    }

    /**
     * Check if the payment is completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === Config::get('payable.statuses.completed', 'completed');
    }

    /**
     * Check if the payment is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === Config::get('payable.statuses.pending', 'pending');
    }

    /**
     * Check if the payment is failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === Config::get('payable.statuses.failed', 'failed');
    }

    /**
     * Check if the payment is processing.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === Config::get('payable.statuses.processing', 'processing');
    }

    /**
     * Check if the payment is refunded.
     *
     * @return bool
     */
    public function isRefunded(): bool
    {
        return in_array($this->status, [
            Config::get('payable.statuses.refunded', 'refunded'),
            Config::get('payable.statuses.partially_refunded', 'partially_refunded'),
        ]);
    }

    /**
     * Get the remaining refundable amount.
     *
     * @return float
     */
    public function getRefundableAmount(): float
    {
        return $this->amount - ($this->refunded_amount ?? 0);
    }

    /**
     * Scope a query to only include completed payments.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', Config::get('payable.statuses.completed', 'completed'));
    }

    /**
     * Scope a query to only include pending payments.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', Config::get('payable.statuses.pending', 'pending'));
    }

    /**
     * Scope a query to only include failed payments.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', Config::get('payable.statuses.failed', 'failed'));
    }

    /**
     * Scope a query to only include offline payments.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOffline($query)
    {
        return $query->where('processor', 'offline');
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
}
