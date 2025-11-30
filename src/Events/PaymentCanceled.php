<?php

namespace Ideacrafters\EloquentPayable\Events;

use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Payment Canceled Event
 *
 * This event is automatically fired when a payment is marked as canceled.
 * Do not fire this event directly. It is managed by the library through:
 * - PaymentLifecycle::markAsCanceled()
 *
 * Listen to this event to handle payment cancellations:
 * Event::listen(PaymentCanceled::class, function ($event) { ... });
 */
class PaymentCanceled
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
