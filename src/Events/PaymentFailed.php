<?php

namespace Ideacrafters\EloquentPayable\Events;

use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Payment Failed Event
 *
 * This event is automatically fired when a payment is marked as failed.
 * Do not fire this event directly. It is managed by the library through:
 * - PaymentLifecycle::markAsFailed()
 *
 * Listen to this event to handle payment failures:
 * Event::listen(PaymentFailed::class, function ($event) { ... });
 */
class PaymentFailed
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
