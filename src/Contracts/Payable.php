<?php

namespace Ideacrafters\EloquentPayable\Contracts;

interface Payable
{
    /**
     * Get the payable amount for the given payer.
     *
     * @param  mixed  $payer
     * @return float
     */
    public function getPayableAmount($payer = null): float;

    /**
     * Check if the item is payable by the given payer.
     *
     * @param  mixed  $payer
     * @return bool
     */
    public function isPayableBy($payer): bool;
}
