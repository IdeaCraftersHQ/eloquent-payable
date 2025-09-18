<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Ideacrafters\EloquentPayable\Traits\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payable as PayableContract;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Carbon\Carbon;

class ExampleInvoice extends Model implements PayableContract
{
    use Payable;

    protected $fillable = [
        'title',
        'description',
        'subtotal',
        'tax_rate',
        'discount_rate',
        'total_amount',
        'currency',
        'due_date',
        'status',
        'client_id',
        'is_active',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'discount_rate' => 'decimal:4',
        'total_amount' => 'decimal:2',
        'due_date' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Get the payable amount for the given payer.
     *
     * @param  Payer|null  $payer
     * @return float
     */
    public function getPayableAmount(?Payer $payer = null): float
    {
        $amount = $this->subtotal;
        
        // Apply discount if payer is eligible
        if ($payer && $this->isEligibleForDiscount($payer)) {
            $amount -= $this->getPayableDiscount($payer);
        }
        
        // Add tax
        $amount += $this->getPayableTax($payer);
        
        return $amount;
    }

    /**
     * Check if the item is payable by the given payer.
     *
     * @param  Payer  $payer
     * @return bool
     */
    public function isPayableBy(Payer $payer): bool
    {
        // Only allow payment by the invoice client
        if ($this->client_id !== $payer->getKey()) {
            return false;
        }
        
        // Check if invoice is in payable status
        if (!in_array($this->status, ['pending', 'overdue'])) {
            return false;
        }
        
        // Check if not already fully paid
        $totalPaid = $this->completedPayments()->sum('amount');
        return $totalPaid < $this->total_amount;
    }

    /**
     * Get the payable item's title/name.
     *
     * @return string
     */
    public function getPayableTitle(): string
    {
        return $this->title ?: "Invoice #{$this->id}";
    }

    /**
     * Get the payable item's description.
     *
     * @return string|null
     */
    public function getPayableDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Get the payable item's currency.
     *
     * @return string
     */
    public function getPayableCurrency(): string
    {
        return $this->currency ?: 'USD';
    }

    /**
     * Get the payable item's metadata.
     *
     * @return array
     */
    public function getPayableMetadata(): array
    {
        return [
            'invoice_id' => $this->id,
            'client_id' => $this->client_id,
            'subtotal' => $this->subtotal,
            'tax_rate' => $this->tax_rate,
            'discount_rate' => $this->discount_rate,
            'due_date' => $this->due_date?->toISOString(),
            'status' => $this->status,
        ];
    }

    /**
     * Get the payable item's tax amount.
     *
     * @param  Payer|null  $payer
     * @return float
     */
    public function getPayableTax(?Payer $payer = null): float
    {
        $amount = $this->subtotal;
        
        // Apply discount if eligible
        if ($payer && $this->isEligibleForDiscount($payer)) {
            $amount -= $this->getPayableDiscount($payer);
        }
        
        return $amount * ($this->tax_rate / 100);
    }

    /**
     * Get the payable item's discount amount.
     *
     * @param  Payer|null  $payer
     * @return float
     */
    public function getPayableDiscount(?Payer $payer = null): float
    {
        if (!$payer || !$this->isEligibleForDiscount($payer)) {
            return 0;
        }
        
        return $this->subtotal * ($this->discount_rate / 100);
    }

    /**
     * Get the payable item's total amount (including tax, minus discount).
     *
     * @param  Payer|null  $payer
     * @return float
     */
    public function getPayableTotal(?Payer $payer = null): float
    {
        return $this->getPayableAmount($payer);
    }

    /**
     * Check if the payable item requires payment.
     *
     * @return bool
     */
    public function requiresPayment(): bool
    {
        return $this->total_amount > 0;
    }

    /**
     * Get the payable item's due date.
     *
     * @return \DateTimeInterface|null
     */
    public function getPayableDueDate(): ?\DateTimeInterface
    {
        return $this->due_date;
    }

    /**
     * Get the payable item's status.
     *
     * @return string
     */
    public function getPayableStatus(): string
    {
        return $this->status;
    }

    /**
     * Check if the payable item is active.
     *
     * @return bool
     */
    public function isPayableActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if the payer is eligible for discount.
     *
     * @param  Payer  $payer
     * @return bool
     */
    protected function isEligibleForDiscount(Payer $payer): bool
    {
        // Example: Check if payer is a premium customer
        if (method_exists($payer, 'isPremium')) {
            return $payer->isPremium();
        }
        
        // Example: Check if payer has made previous payments
        if (method_exists($payer, 'hasPreviousPayments')) {
            return $payer->hasPreviousPayments();
        }
        
        return false;
    }
}
