<?php

namespace Ideacrafters\EloquentPayable\Traits;

use Illuminate\Database\Eloquent\Relations\MorphTo;

trait PaymentCapabilities
{
    use PaymentLifecycle;
    use InteractsWithPaymentProcessor;

    /**
     * Get the payer that owns the payment.
     *
     * @return MorphTo
     */
    public function payer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the payable item that owns the payment.
     *
     * @return MorphTo
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}

