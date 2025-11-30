<?php

namespace Ideacrafters\EloquentPayable\Processors;

/**
 * Processor name constants to avoid magic strings.
 */
class ProcessorNames
{
    public const STRIPE = 'stripe';
    public const SLICKPAY = 'slickpay';
    public const OFFLINE = 'offline';
    public const NONE = 'none';

    /**
     * Get all processor names.
     *
     * @return array
     */
    public static function all(): array
    {
        return [
            self::STRIPE,
            self::SLICKPAY,
            self::OFFLINE,
            self::NONE,
        ];
    }
}

