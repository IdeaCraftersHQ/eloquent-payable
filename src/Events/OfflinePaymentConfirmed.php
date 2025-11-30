<?php

namespace Ideacrafters\EloquentPayable\Events;

use Ideacrafters\EloquentPayable\Models\Payment;

/**
 * Offline Payment Confirmed Event (Deprecated)
 *
 * @deprecated Use PaymentCompleted instead.
 * This event is deprecated and will be removed in a future version.
 * Offline payments that are confirmed via markAsPaid() fire PaymentCompleted event.
 *
 * This event is automatically fired when an offline payment is marked as completed.
 * Do not fire this event directly. It is managed by the library through:
 * - PaymentLifecycle::markAsPaid() (for offline payments)
 *
 * @see PaymentCompleted
 */
class OfflinePaymentConfirmed extends PaymentCompleted
{
    /**
     * Create a new event instance.
     *
     * @param  Payment  $payment
     * @return void
     */
    public function __construct(Payment $payment)
    {
        parent::__construct($payment);
        
        @trigger_error(
            'OfflinePaymentConfirmed is deprecated. Use PaymentCompleted instead.',
            E_USER_DEPRECATED
        );
    }
}

