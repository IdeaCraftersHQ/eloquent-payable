<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Ideacrafters\EloquentPayable\Traits\HasPayments;
use Ideacrafters\EloquentPayable\Contracts\Payer;

class ExampleUser extends Authenticatable implements Payer
{
    use HasPayments;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'is_premium',
        'locale',
        'timezone',
        'billing_address',
        'shipping_address',
        'tax_id',
    ];

    protected $casts = [
        'is_premium' => 'boolean',
        'billing_address' => 'array',
        'shipping_address' => 'array',
    ];

    /**
     * Get the unique identifier for the payer.
     *
     * @return mixed
     */
    public function getKey()
    {
        return $this->getKey();
    }

    /**
     * Get the class name for the payer.
     *
     * @return string
     */
    public function getMorphClass()
    {
        return static::class;
    }

    /**
     * Get the payer's email address.
     *
     * @return string|null
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Get the payer's name.
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Check if the payer can make payments.
     *
     * @return bool
     */
    public function canMakePayments(): bool
    {
        // Example: Check if user is active and verified
        return $this->email_verified_at !== null;
    }

    /**
     * Get the payer's preferred currency.
     *
     * @return string|null
     */
    public function getPreferredCurrency(): ?string
    {
        return $this->preferred_currency ?? 'USD';
    }

    /**
     * Get the payer's billing address.
     *
     * @return array|null
     */
    public function getBillingAddress(): ?array
    {
        return $this->billing_address;
    }

    /**
     * Get the payer's shipping address.
     *
     * @return array|null
     */
    public function getShippingAddress(): ?array
    {
        return $this->shipping_address;
    }

    /**
     * Get the payer's tax ID.
     *
     * @return string|null
     */
    public function getTaxId(): ?string
    {
        return $this->tax_id;
    }

    /**
     * Get the payer's phone number.
     *
     * @return string|null
     */
    public function getPhoneNumber(): ?string
    {
        return $this->phone;
    }

    /**
     * Get the payer's locale.
     *
     * @return string|null
     */
    public function getLocale(): ?string
    {
        return $this->locale ?? 'en';
    }

    /**
     * Get the payer's timezone.
     *
     * @return string|null
     */
    public function getTimezone(): ?string
    {
        return $this->timezone ?? 'UTC';
    }

    /**
     * Get the payer's metadata.
     *
     * @return array
     */
    public function getMetadata(): array
    {
        return [
            'user_id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_premium' => $this->is_premium,
            'locale' => $this->getLocale(),
            'timezone' => $this->getTimezone(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Check if the user is premium.
     *
     * @return bool
     */
    public function isPremium(): bool
    {
        return $this->is_premium;
    }

    /**
     * Check if the user has made previous payments.
     *
     * @return bool
     */
    public function hasPreviousPayments(): bool
    {
        return $this->completedPayments()->count() > 0;
    }
}
