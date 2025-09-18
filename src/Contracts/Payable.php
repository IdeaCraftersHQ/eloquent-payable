<?php

namespace Ideacrafters\EloquentPayable\Contracts;

interface Payable
{
    /**
     * Get the unique identifier for the payable item.
     *
     * @return mixed
     */
    public function getKey();

    /**
     * Get the class name for the payable item.
     *
     * @return string
     */
    public function getMorphClass();

    /**
     * Get the payable amount for the given payer.
     *
     * @param  Payer|null  $payer
     * @return float
     */
    public function getPayableAmount(?Payer $payer = null): float;

    /**
     * Check if the item is payable by the given payer.
     *
     * @param  Payer  $payer
     * @return bool
     */
    public function isPayableBy(Payer $payer): bool;

    /**
     * Get the payable item's title/name.
     *
     * @return string
     */
    public function getPayableTitle(): string;

    /**
     * Get the payable item's description.
     *
     * @return string|null
     */
    public function getPayableDescription(): ?string;

    /**
     * Get the payable item's currency.
     *
     * @return string
     */
    public function getPayableCurrency(): string;

    /**
     * Get the payable item's metadata.
     *
     * @return array
     */
    public function getPayableMetadata(): array;

    /**
     * Get the payable item's tax amount.
     *
     * @param  Payer|null  $payer
     * @return float
     */
    public function getPayableTax(?Payer $payer = null): float;

    /**
     * Get the payable item's discount amount.
     *
     * @param  Payer|null  $payer
     * @return float
     */
    public function getPayableDiscount(?Payer $payer = null): float;

    /**
     * Get the payable item's total amount (including tax, minus discount).
     *
     * @param  Payer|null  $payer
     * @return float
     */
    public function getPayableTotal(?Payer $payer = null): float;

    /**
     * Check if the payable item requires payment.
     *
     * @return bool
     */
    public function requiresPayment(): bool;

    /**
     * Get the payable item's due date.
     *
     * @return \DateTimeInterface|null
     */
    public function getPayableDueDate(): ?\DateTimeInterface;

    /**
     * Get the payable item's status.
     *
     * @return string
     */
    public function getPayableStatus(): string;

    /**
     * Check if the payable item is active.
     *
     * @return bool
     */
    public function isPayableActive(): bool;
}
