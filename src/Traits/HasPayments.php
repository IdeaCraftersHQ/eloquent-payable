<?php

namespace Ideacrafters\EloquentPayable\Traits;

use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasPayments
{
    /**
     * Default: Use model's `email` property when present.
     */
    public function getEmail(): ?string
    {
        return property_exists($this, 'email') ? (string) $this->email : null;
    }

    /**
     * Default: Use model's `name`/`full_name` property when present.
     */
    public function getName(): ?string
    {
        if (property_exists($this, 'name') && $this->name) {
            return (string) $this->name;
        }

        if (property_exists($this, 'full_name') && $this->full_name) {
            return (string) $this->full_name;
        }

        if (property_exists($this, 'first_name') && $this->first_name && property_exists($this, 'last_name') && $this->last_name) {
            return (string) $this->first_name . ' ' . $this->last_name;
        }

        return null;
    }

    /**
     * Default: Use model's `first_name` property when present.
     */
    public function getFirstName(): ?string
    {
        return property_exists($this, 'first_name') ? ($this->first_name ? (string) $this->first_name : null) : null;
    }

    /**
     * Default: Use model's `last_name` property when present.
     */
    public function getLastName(): ?string
    {
        return property_exists($this, 'last_name') ? ($this->last_name ? (string) $this->last_name : null) : null;
    }

    /**
     * Default: Allow payments unless model sets `can_make_payments` false.
     */
    public function canMakePayments(): bool
    {
        return property_exists($this, 'can_make_payments') ? (bool) $this->can_make_payments : true;
    }

    /**
     * Default: Read from payable config `currency`.
     */
    public function getPreferredCurrency(): ?string
    {
        return (string) config('payable.currency');
    }

    /**
     * Default: Use model's `billing_address` array when present.
     */
    public function getBillingAddress(): ?array
    {
        return property_exists($this, 'billing_address') && is_array($this->billing_address)
            ? $this->billing_address
            : null;
    }

    /**
     * Default: Format billing address as string.
     * First checks if `billing_address` property exists and is a string (use it directly).
     * Otherwise, if `billing_address` is an array, formats it.
     */
    public function getBillingAddressAsString(): string
    {
        // Check if billing_address property exists and is a string
        if (property_exists($this, 'billing_address') && is_string($this->billing_address)) {
            return (string) $this->billing_address;
        }

        // Otherwise, format from billing_address array
        $billingAddress = $this->getBillingAddress();

        if ($billingAddress && is_array($billingAddress)) {
            $addressParts = [];

            if (isset($billingAddress['street'])) {
                $addressParts[] = $billingAddress['street'];
            }

            if (isset($billingAddress['city'])) {
                $addressParts[] = $billingAddress['city'];
            }

            if (isset($billingAddress['state'])) {
                $addressParts[] = $billingAddress['state'];
            }

            if (isset($billingAddress['country'])) {
                $addressParts[] = $billingAddress['country'];
            }

            $addressString = implode(', ', $addressParts);
            if (!empty($addressString)) {
                return $addressString;
            }
        }

        return '';
    }

    /**
     * Default: Use model's `shipping_address` array when present.
     */
    public function getShippingAddress(): ?array
    {
        return property_exists($this, 'shipping_address') && is_array($this->shipping_address)
            ? $this->shipping_address
            : null;
    }

    /**
     * Default: Use common tax id fields when present.
     */
    public function getTaxId(): ?string
    {
        if (property_exists($this, 'tax_id') && $this->tax_id) {
            return (string) $this->tax_id;
        }

        if (property_exists($this, 'vat_number') && $this->vat_number) {
            return (string) $this->vat_number;
        }

        return null;
    }

    /**
     * Default: Use model's `phone`/`phone_number` when present.
     */
    public function getPhoneNumber(): ?string
    {
        if (property_exists($this, 'phone') && $this->phone) {
            return (string) $this->phone;
        }

        return property_exists($this, 'phone_number') && $this->phone_number
            ? (string) $this->phone_number
            : null;
    }

    /**
     * Default: Use model's `locale` or fall back to app locale.
     */
    public function getLocale(): ?string
    {
        if (property_exists($this, 'locale') && $this->locale) {
            return (string) $this->locale;
        }

        return (string) config('app.locale');
    }

    /**
     * Default: Use model's `timezone` or fall back to app timezone.
     */
    public function getTimezone(): ?string
    {
        if (property_exists($this, 'timezone') && $this->timezone) {
            return (string) $this->timezone;
        }

        return (string) config('app.timezone');
    }

    /**
     * Default: Use model's `metadata` array or return empty array.
     */
    public function getMetadata(): array
    {
        return property_exists($this, 'metadata') && is_array($this->metadata)
            ? $this->metadata
            : [];
    }

    /**
     * Get the payments made by this payer.
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payer');
    }

    /**
     * Get completed payments made by this payer.
     */
    public function completedPayments(): MorphMany
    {
        return $this->payments()->completed();
    }

    /**
     * Get pending payments made by this payer.
     */
    public function pendingPayments(): MorphMany
    {
        return $this->payments()->pending();
    }

    /**
     * Get failed payments made by this payer.
     */
    public function failedPayments(): MorphMany
    {
        return $this->payments()->failed();
    }

    /**
     * Get offline payments made by this payer.
     */
    public function offlinePayments(): MorphMany
    {
        return $this->payments()->offline();
    }

    /**
     * Get payments made today by this payer.
     */
    public function paymentsToday(): MorphMany
    {
        return $this->payments()->today();
    }

    /**
     * Get payments made this month by this payer.
     */
    public function paymentsThisMonth(): MorphMany
    {
        return $this->payments()->thisMonth();
    }

    /**
     * Get the payment for a specific payable item.
     *
     * @param  mixed  $payable
     * @return Payment|null
     */
    public function paymentFor($payable): ?Payment
    {
        return $this->payments()
            ->where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->latest()
            ->first();
    }

    /**
     * Get the latest payment for a specific payable item.
     *
     * @param  mixed  $payable
     * @return Payment|null
     */
    public function latestPaymentFor($payable): ?Payment
    {
        return $this->paymentFor($payable);
    }

    /**
     * Get all completed payments for a specific payable item.
     *
     * @param  mixed  $payable
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function completedPaymentsFor($payable)
    {
        return $this->payments()
            ->where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->completed()
            ->get();
    }

    /**
     * Get the total amount paid by this payer.
     *
     * @return float
     */
    public function getTotalPaid(): float
    {
        return $this->completedPayments()->sum('amount');
    }

    /**
     * Get the total amount paid this month by this payer.
     *
     * @return float
     */
    public function getTotalPaidThisMonth(): float
    {
        return $this->paymentsThisMonth()->completed()->sum('amount');
    }

    /**
     * Check if this payer has paid for a specific payable item.
     *
     * @param  mixed  $payable
     * @return bool
     */
    public function hasPaidFor($payable): bool
    {
        return $this->completedPaymentsFor($payable)->isNotEmpty();
    }

    /**
     * Check if this payer has a pending payment for a specific payable item.
     *
     * @param  mixed  $payable
     * @return bool
     */
    public function hasPendingPaymentFor($payable): bool
    {
        return $this->payments()
            ->where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->pending()
            ->exists();
    }
}
