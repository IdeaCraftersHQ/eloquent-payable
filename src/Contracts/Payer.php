<?php

namespace Ideacrafters\EloquentPayable\Contracts;

interface Payer
{
    /**
     * Get the unique identifier for the payer.
     *
     * @return mixed
     */
    public function getKey();

    /**
     * Get the class name for the payer.
     *
     * @return string
     */
    public function getMorphClass();

    /**
     * Get the payer's email address.
     *
     * @return string|null
     */
    public function getEmail(): ?string;

    /**
     * Get the payer's name.
     *
     * @return string|null
     */
    public function getName(): ?string;

    /**
     * Get the payer's first name.
     *
     * @return string|null
     */
    public function getFirstName(): ?string;

    /**
     * Get the payer's last name.
     *
     * @return string|null
     */
    public function getLastName(): ?string;

    /**
     * Check if the payer can make payments.
     *
     * @return bool
     */
    public function canMakePayments(): bool;

    /**
     * Get the payer's preferred currency.
     *
     * @return string|null
     */
    public function getPreferredCurrency(): ?string;

    /**
     * Get the payer's billing address.
     *
     * @return array|null
     */
    public function getBillingAddress(): ?array;

    /**
     * Get the payer's billing address as a formatted string.
     *
     * @return string
     */
    public function getBillingAddressAsString(): string;

    /**
     * Get the payer's shipping address.
     *
     * @return array|null
     */
    public function getShippingAddress(): ?array;

    /**
     * Get the payer's tax ID.
     *
     * @return string|null
     */
    public function getTaxId(): ?string;

    /**
     * Get the payer's phone number.
     *
     * @return string|null
     */
    public function getPhoneNumber(): ?string;

    /**
     * Get the payer's locale.
     *
     * @return string|null
     */
    public function getLocale(): ?string;

    /**
     * Get the payer's timezone.
     *
     * @return string|null
     */
    public function getTimezone(): ?string;

    /**
     * Get the payer's metadata.
     *
     * @return array
     */
    public function getMetadata(): array;
}
