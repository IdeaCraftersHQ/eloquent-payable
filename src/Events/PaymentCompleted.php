<?php

namespace Ideacrafters\EloquentPayable\Events;

use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Payment Completed Event
 *
 * This event is automatically fired when a payment is marked as completed.
 * Do not fire this event directly. It is managed by the library through:
 * - PaymentLifecycle::markAsPaid()
 *
 * Listen to this event to handle payment completion:
 * Event::listen(PaymentCompleted::class, function ($event) { ... });
 */
class PaymentCompleted
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
