<?php

namespace Ideacrafters\EloquentPayable\Traits;

use Ideacrafters\EloquentPayable\Contracts\PaymentProcessor;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

trait InteractsWithPaymentProcessor
{
    /**
     * Get the processor instance for this payment.
     *
     * @return PaymentProcessor
     */
    public function getProcessor(): PaymentProcessor
    {
        $processors = Config::get('payable.processors', []);
        $processorClass = $processors[$this->processor] ?? null;

        if (!$processorClass) {
            throw new PaymentException("Unknown processor: {$this->processor}");
        }

        return App::make($processorClass);
    }

    /**
     * Check if the payment is offline.
     *
     * @return bool
     */
    public function isOffline(): bool
    {
        return $this->getProcessor()->isOffline();
    }
}

