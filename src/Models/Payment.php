<?php

namespace Ideacrafters\EloquentPayable\Models;

use Illuminate\Database\Eloquent\Model;
use Ideacrafters\EloquentPayable\Traits\PaymentCapabilities;
use Illuminate\Support\Facades\Config;

class Payment extends Model
{
    use PaymentCapabilities;
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
        'canceled_at',
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
        'canceled_at' => 'datetime',
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
     * Get the remaining refundable amount.
     *
     * @return float
     */
    public function getRefundableAmount(): float
    {
        return $this->amount - ($this->refunded_amount ?? 0);
    }
}
