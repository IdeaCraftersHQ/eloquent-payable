<?php

namespace Ideacrafters\EloquentPayable\Events;

use Ideacrafters\EloquentPayable\Models\Payment;

/**
 * Offline Payment Created Event (Deprecated)
 *
 * @deprecated Use PaymentCreated with isOffline flag instead.
 * This event is deprecated and will be removed in a future version.
 * Use: event(new PaymentCreated($payment, true)) instead.
 *
 * This event is automatically fired by payment processors when an offline payment is created.
 * Do not fire this event directly. It is managed by the library through:
 * - BaseProcessor::process() (for offline payments)
 * - BaseProcessor::createRedirect() (for offline redirect payments)
 *
 * @see PaymentCreated
 */
class OfflinePaymentCreated extends PaymentCreated
{
    /**
     * Create a new event instance.
     *
     * @param  Payment  $payment
     * @return void
     */
    public function __construct(Payment $payment)
    {
        parent::__construct($payment, true);
        
        @trigger_error(
            'OfflinePaymentCreated is deprecated. Use PaymentCreated with isOffline flag instead.',
            E_USER_DEPRECATED
        );
    }
}

