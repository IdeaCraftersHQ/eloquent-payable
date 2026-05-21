<?php

namespace Ideacrafters\EloquentPayable\Credentials;

use Closure;
use Ideacrafters\EloquentPayable\Models\Payment;

/**
 * Invocable wrapper around the host-supplied resolver closure. The wrapper
 * exists so the manager has a named type to register/return rather than
 * passing bare Closures around, and so future versions can layer
 * instrumentation (logging, metrics, caching) without touching call sites.
 *
 * Callback signature: `fn (Payment $payment): ?array`.
 *   - Return a credentials array → processor uses those credentials.
 *   - Return null → processor falls back to env credentials.
 *   - Throw → propagates as a PaymentException; the payment is marked failed.
 *
 * The Payment passed in already has `metadata.merchant_pointer` populated by
 * the processor (when the host supplied one in `$options` at create time),
 * so the same resolver works at create, confirm, and refund time.
 */
final class CredentialResolver
{
    public function __construct(
        private readonly Closure $callback,
    ) {}

    public function __invoke(Payment $payment): ?array
    {
        return ($this->callback)($payment);
    }
}
