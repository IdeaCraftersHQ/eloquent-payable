<?php

namespace Ideacrafters\EloquentPayable;

use Illuminate\Support\Facades\Config;

/**
 * Payment status values from configuration.
 * Acts as an enum-like class that reads values from config.
 */
class PaymentStatus
{
    /**
     * Get the completed status value.
     *
     * @return string
     */
    public static function completed(): string
    {
        return Config::get('payable.statuses.completed', 'completed');
    }

    /**
     * Get the failed status value.
     *
     * @return string
     */
    public static function failed(): string
    {
        return Config::get('payable.statuses.failed', 'failed');
    }

    /**
     * Get the canceled status value.
     *
     * @return string
     */
    public static function canceled(): string
    {
        return Config::get('payable.statuses.canceled', 'canceled');
    }

    /**
     * Get the pending status value.
     *
     * @return string
     */
    public static function pending(): string
    {
        return Config::get('payable.statuses.pending', 'pending');
    }

    /**
     * Get the processing status value.
     *
     * @return string
     */
    public static function processing(): string
    {
        return Config::get('payable.statuses.processing', 'processing');
    }

    /**
     * Get the refunded status value.
     *
     * @return string
     */
    public static function refunded(): string
    {
        return Config::get('payable.statuses.refunded', 'refunded');
    }

    /**
     * Get the partially refunded status value.
     *
     * @return string
     */
    public static function partiallyRefunded(): string
    {
        return Config::get('payable.statuses.partially_refunded', 'partially_refunded');
    }
}

