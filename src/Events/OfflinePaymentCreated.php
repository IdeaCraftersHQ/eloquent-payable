<?php

namespace Ideacrafters\EloquentPayable\Events;

use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OfflinePaymentCreated
{
    use Dispatchable, SerializesModels;

    /**
     * The payment instance.
     *
     * @var Payment
     */
    public $payment;

    /**
     * Create a new event instance.
     *
     * @param  Payment  $payment
     * @return void
     */
    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }
}
