<?php

namespace Ideacrafters\EloquentPayable\Exceptions;

/**
 * Thrown when a resolver returns a malformed credential bundle — typically
 * a partial set of required fields (e.g. SATIM `username` without `password`).
 *
 * Mixing one tenant's `username` with another tenant's `password` (or with
 * the platform's password from env) silently produces auth errors that are
 * painful to debug. Failing loudly on bundle validation prevents that.
 */
class InvalidCredentialBundleException extends PaymentException
{
    //
}
