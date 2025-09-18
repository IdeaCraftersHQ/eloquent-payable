<?php

namespace Ideacrafters\EloquentPayable\Events;

use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentRefunded
{
    use Dispatchable, SerializesModels;

    /**
     * The payment instance.
     *
     * @var Payment
     */
    public $payment;

    /**
     * The refund amount.
     *
     * @var float
     */
    public $refundAmount;

    /**
     * Create a new event instance.
     *
     * @param  Payment  $payment
     * @param  float  $refundAmount
     * @return void
     */
    public function __construct(Payment $payment, float $refundAmount)
    {
        $this->payment = $payment;
        $this->refundAmount = $refundAmount;
    }
}
