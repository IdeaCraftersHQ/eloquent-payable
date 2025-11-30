<?php

namespace Ideacrafters\EloquentPayable\Events;

use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Payment Refunded Event
 *
 * This event is automatically fired when a payment is refunded.
 * Do not fire this event directly. It is managed by the library through:
 * - PaymentProcessor::refund()
 *
 * Listen to this event to handle payment refunds:
 * Event::listen(PaymentRefunded::class, function ($event) { ... });
 */
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
