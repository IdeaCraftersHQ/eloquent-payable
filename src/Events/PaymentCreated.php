<?php

namespace Ideacrafters\EloquentPayable\Events;

use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Payment Created Event
 *
 * This event is automatically fired by payment processors when a payment is created.
 * Do not fire this event directly. It is managed by the library through:
 * - BaseProcessor::process()
 * - BaseProcessor::createRedirect()
 *
 * Listen to this event to handle payment creation:
 * Event::listen(PaymentCreated::class, function ($event) { ... });
 */
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
