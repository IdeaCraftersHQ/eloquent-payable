<?php

namespace Ideacrafters\EloquentPayable\Exceptions;

/**
 * Thrown when a gateway reports a charged amount that disagrees with the
 * amount recorded on the local Payment. The payment is left in a failed
 * state rather than completed, since fulfilling it would honour a charge
 * the application never authorised at that magnitude.
 */
class PaymentAmountMismatchException extends PaymentException
{
    //
}
