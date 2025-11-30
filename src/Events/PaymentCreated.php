<?php

namespace Ideacrafters\EloquentPayable\Events;

use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentCreated
{
    use Dispatchable, SerializesModels;

    /**
     * The payment instance.
     *
     * @var Payment
     */
    public $payment;

    /**
     * Whether this is an offline payment.
     *
     * @var bool
     */
    public $isOffline;

    /**
     * Create a new event instance.
     *
     * @param  Payment  $payment
     * @param  bool  $isOffline
     * @return void
     */
    public function __construct(Payment $payment, bool $isOffline = false)
    {
        $this->payment = $payment;
        $this->isOffline = $isOffline;
    }
}
