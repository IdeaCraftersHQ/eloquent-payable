# Migration Guide: From 1.x.x to 2.0.0

This guide will help you migrate your application from version 1.x.x to version 2.0.0, which introduces several new features and improvements while maintaining backward compatibility.

## Quick Migration Checklist

**Follow these steps in order:**

1. [ ] Pull the latest version
2. [ ] Run database migration: `php artisan migrate`
3. [ ] Update `config/payable.php` with new configuration sections
4. [ ] Add new environment variables to `.env` (if needed)
5. [ ] **If you have custom processors**: Refactor to use protected `do[Action]()` methods (see [Custom Processor Refactoring](#custom-processor-refactoring-breaking-change-for-custom-processors))
6. [ ] Remove manual `event()` calls for payment events from your code
7. [ ] Update event listeners if using deprecated offline payment events
8. [ ] Update status checks to use `PaymentStatus::*()` (optional but recommended)
9. [ ] Handle canceled payments if relevant to your application
10. [ ] Run your test suite
11. [ ] Test in staging environment before production deployment

## Table of Contents

1. [Required Changes](#required-changes)
   - [Database Migration](#database-migration-required)
   - [Event System Changes](#event-system-changes-action-required)
   - [Custom Processor Refactoring](#custom-processor-refactoring-breaking-change-for-custom-processors)
   - [Configuration Updates](#configuration-updates-required)
2. [Recommended Updates](#recommended-updates)
   - [Using PaymentStatus Class](#update-status-access-optional-but-recommended)
   - [Handling Canceled Payments](#handle-canceled-payments)
   - [Updating Deprecated Events](#deprecated-events-migration-recommended)
3. [New Features](#new-features)
4. [Event System Deep Dive](#event-system-deep-dive)
5. [Custom Processor Refactoring (Detailed)](#custom-processor-refactoring-detailed)
6. [Testing Your Migration](#testing-your-migration)
7. [Internal Architecture Improvements](#internal-architecture-improvements)
8. [Rollback Plan](#rollback-plan)

## Overview

This version introduces:
- **Payment Canceled State**: New `canceled` status for payments
- **Currency Validation**: Automatic currency validation per processor
- **Enhanced Event System**: Configurable events (global and per-processor) with automatic event firing
- **PaymentStatus Class**: Centralized status access
- **Automatic Lifecycle Management**: Automatic timestamp management
- **Payment Model Refactoring**: Improved code organization with traits

## Required Changes

⚠️ **These steps are mandatory for the migration to work correctly.**

### Database Migration Required

**CRITICAL**: You must run a database migration to add the `canceled_at` column.

```bash
php artisan migrate
```

This will run:
- `2024_01_02_000000_add_canceled_at_to_payments_table.php`

The migration adds:
- `canceled_at` timestamp column (nullable)
- Index on `canceled_at` column

**Verify the migration:**
```bash
php artisan migrate:status
```

### Event System Changes (Action Required)

**IMPORTANT**: The event system has changed significantly. You need to understand and potentially update your code.

**Key Changes:**
1. Events are now **automatically fired** by the library (you should NOT fire them manually)
2. Event configuration is now available (new feature)
3. `OfflinePaymentCreated` and `OfflinePaymentConfirmed` are deprecated

**What You Need to Do:**
- Remove any manual `event()` calls for payment events from your code
- Update event listeners if you're using deprecated offline payment events
- Review the [Event System Changes](#event-system-changes-action-required) section below for details

### Deprecated Events (Migration Recommended)

The following events are deprecated but still work:
- `OfflinePaymentCreated` → Use `PaymentCompleted` with `isOffline` flag
- `OfflinePaymentConfirmed` → Use `PaymentCompleted`

You'll see deprecation warnings. Plan to migrate (see [Deprecated Events](#deprecated-events-migration-recommended) section).

### Custom Processor Refactoring (Breaking Change for Custom Processors)

**⚠️ IMPORTANT**: If you have created custom payment processors, you **must** refactor them to align with the new architecture.

#### TL;DR - Quick Steps for Custom Processors

If you have custom processors, you **MUST** do this:

1. **Delete** your public `process()`, `createRedirect()`, `refund()`, `cancel()` methods
2. **Implement** protected `doProcess()`, `doCreateRedirect()`, `doRefund()`, `doCancel()` methods instead
3. **Remove** all event firing code (handled automatically by `BaseProcessor`)
4. **Remove** all validation logic (handled automatically by `BaseProcessor`)
5. **Keep ONLY** processor-specific logic in the protected methods

See the [detailed section](#custom-processor-refactoring-detailed) below for complete examples.

#### Detailed Migration Guide

**Before (Old Architecture):**
```php
class MyCustomProcessor extends BaseProcessor
{
    // You implemented public methods directly
    public function process(Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        // Your validation logic
        // Your payment creation
        // Your event firing
        // Your processor-specific logic
    }
    
    public function createRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): PaymentRedirect
    {
        // Your validation logic
        // Your redirect creation
        // Your event firing
        // Your processor-specific logic
    }
}
```

**After (New Architecture):**
```php
use Ideacrafters\EloquentPayable\Processors\BaseProcessor;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Models\PaymentRedirectModel;
use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\PaymentStatus;

class MyCustomProcessor extends BaseProcessor
{
    // Now implement protected do[Action]() methods instead
    protected function doProcess(Payment $payment, Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        // ONLY your processor-specific logic here
        // Validation, payment creation, and event emission are handled by BaseProcessor
        
        $payment->update([
            'reference' => $this->createCustomReference(),
            'status' => PaymentStatus::processing(),
            'metadata' => array_merge($payment->metadata ?? [], [
                'custom_data' => $this->getCustomData(),
            ]),
        ]);
        
        return $payment;
    }
    
    protected function doCreateRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): array
    {
        // ONLY your processor-specific redirect logic
        // Validation and event emission are handled by BaseProcessor
        
        // Use processPaymentWithoutEvents() to create payment without firing events
        // Events will fire automatically after doCreateRedirect() completes
        $payment = $this->processPaymentWithoutEvents($payable, $payer, $amount, $options);
        
        $redirectUrl = $this->createRedirectUrl($payment);
        
        return [
            'payment' => $payment,
            'redirect' => new PaymentRedirectModel(
                redirectUrl: $redirectUrl,
                successUrl: $options['success_url'] ?? null,
                cancelUrl: $options['cancel_url'] ?? null,
                failureUrl: $options['failure_url'] ?? null,
            ),
        ];
    }
    
    // Implement other protected methods as needed
    protected function doRefund(Payment $payment, ?float $amount = null): Payment
    {
        // Your custom refund logic
        return $payment;
    }
    
    protected function doCancel(Payment $payment, ?string $reason = null): Payment
    {
        // Your custom cancellation logic
        return $payment;
    }
}
```

**Key Changes:**
1. **Remove public method implementations** - `process()`, `createRedirect()`, `refund()`, `cancel()` are now implemented in `BaseProcessor`
2. **Implement protected `do[Action]()` methods** - These contain only your processor-specific logic
3. **Remove validation code** - Validation is now handled by `BaseProcessor`
4. **Remove event firing** - Events are automatically fired by `BaseProcessor`
5. **Use `processPaymentWithoutEvents()`** - For creating payments in `doCreateRedirect()` without firing events (events fire automatically after)

**What You Get:**
- Automatic validation (payable, payer, amount, currency)
- Automatic event emission
- Consistent behavior with built-in processors
- Less code to maintain
- No risk of forgetting validation or events

**Migration Steps:**
1. Change public methods to protected `do[Action]()` methods
2. Remove validation logic (handled by `BaseProcessor`)
3. Remove event firing code (handled by `BaseProcessor`)
4. Keep only processor-specific logic in the `do[Action]()` methods
5. Test your custom processor thoroughly

## Recommended Updates

These changes are **optional but recommended** for better code quality and future compatibility.

### 1. Update Status Access (Optional but Recommended)

### 1. Payment Canceled State

A new payment status `canceled` has been added to handle payment cancellations.

**Usage:**
```php
// Cancel a payment
$payment->markAsCanceled();

// Check if canceled
if ($payment->isCanceled()) {
    // Handle canceled payment
}

// Query canceled payments
$canceledPayments = Payment::canceled()->get();
```

### 2. PaymentStatus Class

A new centralized class for accessing payment statuses.

**Before:**
```php
$status = Config::get('payable.statuses.completed');
```

**After (Recommended):**
```php
use Ideacrafters\EloquentPayable\PaymentStatus;

$status = PaymentStatus::completed();
```

### 3. Currency Validation

Processors now automatically validate currency before processing payments.

**Behavior:**
- Stripe supports multiple currencies
- Slickpay only supports DZD
- Other processors use their default currency
- Throws `PaymentException` if unsupported currency is used

### 4. Configurable Event System

Events can now be disabled globally or per-processor.

**Configuration:**
```php
'events' => [
    'enabled' => env('PAYABLE_EVENTS_ENABLED', true),
    'processors' => [
        'stripe' => false,  // Disable events for Stripe
        'slickpay' => true, // Enable events for Slickpay
    ],
],
```

## Configuration Updates (Required)

### Update config/payable.php

Add the following new configuration sections:

```php
return [
    // ... existing configuration ...
    
    // Add canceled status
    'statuses' => [
        'pending' => 'pending',
        'processing' => 'processing',
        'completed' => 'completed',
        'failed' => 'failed',
        'refunded' => 'refunded',
        'partially_refunded' => 'partially_refunded',
        'canceled' => 'canceled', // NEW
    ],
    
    // Slickpay configuration (already exists in main, but may have new options)
    'slickpay' => [
        'api_key' => env('SLICKPAY_API_KEY'),
        'sandbox_mode' => env('SLICKPAY_SANDBOX_MODE', true),
        'dev_api' => env('SLICKPAY_DEV_API', 'https://devapi.slick-pay.com/api/v2'),
        'prod_api' => env('SLICKPAY_PROD_API', 'https://prodapi.slick-pay.com/api/v2'),
        'fallbacks' => [
            'first_name' => env('SLICKPAY_FALLBACK_FIRST_NAME', 'Customer'),
            'last_name' => env('SLICKPAY_FALLBACK_LAST_NAME', 'User'),
            'address' => env('SLICKPAY_FALLBACK_ADDRESS', 'Not provided'),
            'phone' => env('SLICKPAY_FALLBACK_PHONE', '0000000000'),
            'email' => env('SLICKPAY_FALLBACK_EMAIL', 'customer@example.com'),
        ],
    ],
    
    // Add event configuration
    'events' => [
        'enabled' => env('PAYABLE_EVENTS_ENABLED', true),
        'processors' => [
            // Configure per-processor event settings
        ],
    ],
    
    // Add webhook idempotency configuration
    'webhooks' => [
        'verify_signature' => env('PAYABLE_VERIFY_WEBHOOK_SIGNATURE', true),
        'timeout' => env('PAYABLE_WEBHOOK_TIMEOUT', 30),
        'event_idempotency_ttl_days' => env('PAYABLE_WEBHOOK_EVENT_IDEMPOTENCY_TTL_DAYS', 30), // NEW
    ],
];
```

### Update .env File

Add new environment variables if needed:

```env
# Slickpay Configuration (already exists in main)
SLICKPAY_API_KEY=your_api_key
SLICKPAY_SANDBOX_MODE=true

# Event Configuration (optional)
PAYABLE_EVENTS_ENABLED=true

# Webhook Configuration (optional)
PAYABLE_WEBHOOK_EVENT_IDEMPOTENCY_TTL_DAYS=30
```

## Code Updates (Recommended)

**Before:**
```php
if ($payment->status === Config::get('payable.statuses.completed')) {
    // ...
}
```

**After:**
```php
use Ideacrafters\EloquentPayable\PaymentStatus;

if ($payment->status === PaymentStatus::completed()) {
    // ...
}
```

### 2. Handle Canceled Payments

If you need to handle canceled payments:

```php
// Cancel a payment
$payment->markAsCanceled();

// Listen to canceled event
use Ideacrafters\EloquentPayable\Events\PaymentCanceled;

Event::listen(PaymentCanceled::class, function ($event) {
    // Handle payment cancellation
    Log::info('Payment canceled', ['payment_id' => $event->payment->id]);
});
```

### 3. Update Deprecated Events

See the [Deprecated Events](#deprecated-events-migration-recommended) section below for migration details.

## New Features

### 1. Payment Canceled State

A new payment status `canceled` has been added to handle payment cancellations.

**Usage:**
```php
// Cancel a payment
$payment->markAsCanceled();

// Check if canceled
if ($payment->isCanceled()) {
    // Handle canceled payment
}

// Query canceled payments
$canceledPayments = Payment::canceled()->get();
```

### 2. PaymentStatus Class

A new centralized class for accessing payment statuses.

**Before:**
```php
$status = Config::get('payable.statuses.completed');
```

**After (Recommended):**
```php
use Ideacrafters\EloquentPayable\PaymentStatus;

$status = PaymentStatus::completed();
```

### 3. Currency Validation

Processors now automatically validate currency before processing payments.

**Behavior:**
- Stripe supports multiple currencies
- Slickpay only supports DZD
- Other processors use their default currency
- Throws `PaymentException` if unsupported currency is used

**Example:**
```php
// Stripe - supports multiple currencies
$invoice->pay($client, 100.00, [
    'processor' => 'stripe',
    'currency' => 'EUR' // Valid
]);

// Slickpay - only supports DZD
$invoice->pay($client, 100.00, [
    'processor' => 'slickpay',
    'currency' => 'DZD' // Required
]);
```

### 4. Configurable Event System

Events can now be disabled globally or per-processor. See [Event System Deep Dive](#event-system-deep-dive) for details.

## Event System Deep Dive

**Important Change:** The event system has been completely refactored. All events are now automatically fired by the library.

### Automatic Event Firing

**Before (Previous Behavior):**
- Events were **not** automatically fired by processors or lifecycle methods like `markAsFailed()`, `markAsPaid()`, etc.
- Events had to be manually fired by user code after calling these methods
- This led to inconsistent behavior where events might fire in some places but not others

**After (Current Behavior):**
- Events are **automatically fired** by:
  - Payment processors (`BaseProcessor::process()`, `BaseProcessor::createRedirect()`)
  - Payment lifecycle methods (`markAsPaid()`, `markAsFailed()`, `markAsCanceled()`)
- **Event firing is now discouraged in user code** - you should not manually fire payment events
- Events fire uniformly and consistently across all payment operations

**What This Means for You:**

1. **Remove manual event firing** from your code. The library handles it automatically:
   ```php
   // ❌ DON'T DO THIS (events are now automatic)
   $payment->markAsPaid();
   event(new PaymentCompleted($payment)); // Remove this line
   
   // ✅ DO THIS (event fires automatically)
   $payment->markAsPaid(); // PaymentCompleted event fires automatically
   ```

2. **If you override lifecycle methods or processor methods**, you can fire events in your overrides, but only if you're extending the behavior:
   ```php
   use Ideacrafters\EloquentPayable\Models\Payment;
   use Ideacrafters\EloquentPayable\Processors\BaseProcessor;
   use Ideacrafters\EloquentPayable\Events\PaymentCompleted;
   
   // ✅ OK: Override and fire event in custom lifecycle method
   class CustomPayment extends Payment
   {
       public function markAsPaid($paidAt = null)
       {
           // Your custom logic
           parent::markAsPaid($paidAt); // This fires PaymentCompleted automatically
           
           // Fire additional custom event if needed
           event(new CustomPaymentEvent($this));
       }
   }
   
   // ✅ OK: Override processor method and fire event
   class CustomProcessor extends BaseProcessor
   {
       protected function doProcess(Payment $payment, Payable $payable, Payer $payer, float $amount, array $options = []): Payment
       {
           // Your custom logic
           // Note: PaymentCreated event fires automatically after doProcess() completes
           
           // Fire additional custom event if needed
           event(new CustomProcessorEvent($payment));
           
           return $payment;
       }
   }
   ```

3. **Listen to events instead of firing them** - use event listeners to react to payment state changes:
   ```php
   use Illuminate\Support\Facades\Event;
   use Ideacrafters\EloquentPayable\Events\PaymentCompleted;
   
   // ✅ DO THIS: Listen to automatically fired events
   Event::listen(PaymentCompleted::class, function ($event) {
       // Handle payment completion
   });
   ```

**Benefits:**
- **Predictable**: Events always fire at the right time
- **Consistent**: Same events fire regardless of how the payment state changes
- **Maintainable**: No need to remember to fire events manually
- **Reliable**: No risk of forgetting to fire events in some code paths

### Deprecated Events (Migration Recommended)

The following events are deprecated but still work for backward compatibility:

- `OfflinePaymentCreated` → Use `PaymentCreated` with `isOffline` flag
- `OfflinePaymentConfirmed` → Use `PaymentCompleted`

**Migration Path:**

**Before:**
```php
use Ideacrafters\EloquentPayable\Events\OfflinePaymentCreated;
use Ideacrafters\EloquentPayable\Events\OfflinePaymentConfirmed;

Event::listen(OfflinePaymentCreated::class, function ($event) {
    // Handle offline payment creation
});

Event::listen(OfflinePaymentConfirmed::class, function ($event) {
    // Handle offline payment confirmation
});
```

**After:**
```php
use Ideacrafters\EloquentPayable\Events\PaymentCreated;
use Ideacrafters\EloquentPayable\Events\PaymentCompleted;

Event::listen(PaymentCreated::class, function ($event) {
    if ($event->isOffline) {
        // Handle offline payment creation
    }
});

Event::listen(PaymentCompleted::class, function ($event) {
    if ($event->payment->isOffline()) {
        // Handle offline payment confirmation
    }
});
```

### New Event: PaymentCanceled

A new event is fired when payments are canceled:

```php
use Illuminate\Support\Facades\Event;
use Ideacrafters\EloquentPayable\Events\PaymentCanceled;

Event::listen(PaymentCanceled::class, function ($event) {
    // Handle payment cancellation
});
```

### Event Configuration

**New Feature:** Event configuration is a brand new feature introduced with automatic event management.

This feature was added to give users flexibility and control over event emissions, allowing you to customize which processors emit events. This is particularly useful when:
- You want to disable events for specific processors that generate high volume
- You need to reduce event overhead for certain payment types
- You want fine-grained control over your event-driven architecture

**Configuration:**

```php
// In config/payable.php
'events' => [
    'enabled' => env('PAYABLE_EVENTS_ENABLED', true), // Global toggle
    'processors' => [
        'stripe' => false,  // Disable events for Stripe processor
        'slickpay' => true, // Enable events for Slickpay processor
        'offline' => false, // Disable events for Offline processor
    ],
],
```

**How It Works:**
- Global `enabled` setting controls all events (defaults to `true`)
- Processor-specific settings override the global setting
- If a processor setting is not specified, it uses the global setting
- Events respect this configuration before firing

## Custom Processor Refactoring (Detailed)

This section provides complete examples and detailed guidance for refactoring custom processors.

**Key Changes:**
1. **Remove public method implementations** - `process()`, `createRedirect()`, `refund()`, `cancel()` are now implemented in `BaseProcessor`
2. **Implement protected `do[Action]()` methods** - These contain only your processor-specific logic
3. **Remove validation code** - Validation is now handled by `BaseProcessor`
4. **Remove event firing** - Events are automatically fired by `BaseProcessor`
5. **Use `processPaymentWithoutEvents()`** - For creating payments in `doCreateRedirect()` without firing events (events fire automatically after)

**What You Get:**
- Automatic validation (payable, payer, amount, currency)
- Automatic event emission
- Consistent behavior with built-in processors
- Less code to maintain
- No risk of forgetting validation or events

**Complete Example:**

```php
use Ideacrafters\EloquentPayable\Processors\BaseProcessor;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Models\PaymentRedirectModel;
use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\PaymentStatus;

class MyCustomProcessor extends BaseProcessor
{
    // Only implement processor-specific logic
    protected function doProcess(Payment $payment, Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        // Your custom payment processing logic here
        // Validation and event emission are handled by BaseProcessor::process()
        
        $payment->update([
            'reference' => $this->createCustomReference(),
            'status' => PaymentStatus::processing(),
            'metadata' => array_merge($payment->metadata ?? [], [
                'custom_data' => $this->getCustomData(),
            ]),
        ]);
        
        return $payment;
    }
    
    protected function doCreateRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): array
    {
        // Use processPaymentWithoutEvents() to create payment without firing events
        // Events will fire automatically after doCreateRedirect() completes
        $payment = $this->processPaymentWithoutEvents($payable, $payer, $amount, $options);
        
        $redirectUrl = $this->createRedirectUrl($payment);
        
        return [
            'payment' => $payment,
            'redirect' => new PaymentRedirectModel(
                redirectUrl: $redirectUrl,
                successUrl: $options['success_url'] ?? null,
                cancelUrl: $options['cancel_url'] ?? null,
                failureUrl: $options['failure_url'] ?? null,
            ),
        ];
    }
    
    protected function doRefund(Payment $payment, ?float $amount = null): Payment
    {
        // Your custom refund logic
        return $payment;
    }
    
    protected function doCancel(Payment $payment, ?string $reason = null): Payment
    {
        // Your custom cancellation logic
        return $payment;
    }
}
```

**Migration Steps:**
1. Change public methods to protected `do[Action]()` methods
2. Remove validation logic (handled by `BaseProcessor`)
3. Remove event firing code (handled by `BaseProcessor`)
4. Keep only processor-specific logic in the `do[Action]()` methods
5. Test your custom processor thoroughly

## Testing Your Migration

### 1. Run Tests

```bash
composer test
```

### 2. Test Payment Cancellation

```php
// Test canceling a payment
$payment = Payment::create([...]);
$payment->markAsCanceled();

$this->assertTrue($payment->isCanceled());
$this->assertNotNull($payment->canceled_at);
```

### 3. Test Currency Validation

```php
// Test that unsupported currencies throw exceptions
$this->expectException(PaymentException::class);
$invoice->pay($client, 100.00, [
    'processor' => 'slickpay',
    'currency' => 'USD' // Should fail - Slickpay only supports DZD
]);
```

### 4. Test Event Configuration

```php
// Test that events can be disabled
Config::set('payable.events.enabled', false);
$payment = $invoice->pay($client, 100.00);
// PaymentCreated event should not fire
```

### 5. Verify Database Schema

```php
// Verify canceled_at column exists
Schema::hasColumn('payments', 'canceled_at'); // Should return true
```

## Internal Architecture Improvements

These are internal improvements that don't require action but improve the codebase:

### Automatic Timestamp Management

The library now automatically manages payment timestamps:
- `paid_at` is set when status becomes `completed`
- `failed_at` is set when status becomes `failed`
- `canceled_at` is set when status becomes `canceled`
- Timestamps are mutually exclusive and automatically cleared on status changes

You no longer need to manually manage these timestamps.

### Payment Model Refactoring

**Important Improvement:** The Payment model has been refactored into reusable traits, making the code more flexible and allowing you to use your own custom payment models with the same functionality.

**Before (Previous Structure):**
- Lifecycle methods (`markAsPaid()`, `markAsFailed()`, `markAsCanceled()`, etc.) were directly in the `Payment` class
- Status check methods (`isCompleted()`, `isFailed()`, `isCanceled()`, etc.) were tied to the `Payment` class
- Event emission logic was embedded in the `Payment` class
- While you could configure a custom payment model in config, you couldn't easily reuse the lifecycle and event logic
- This limited flexibility - if you wanted to use your own payment model, you'd have to duplicate all the lifecycle logic

**After (Current Structure):**
The Payment model now uses reusable traits:
- `PaymentCapabilities` - Main trait that combines all sub-traits and relationships
- `PaymentLifecycle` - Status management, lifecycle methods, and status check methods
- `InteractsWithPaymentProcessor` - Processor access and offline checking
- `InteractsWithPaymentEvents` - Event configuration and emission logic

**Benefits:**
- **Reusable**: You can now use these traits in your own custom payment models
- **Flexible**: Configure your own payment model in config and still get all the lifecycle functionality
- **Maintainable**: Changes to lifecycle logic happen in one place (the trait)
- **Consistent**: Same behavior whether you use the default Payment model or your own

**Using Your Own Payment Model:**

You can now easily create your own payment model with all the same functionality:

```php
// In config/payable.php
'models' => [
    'payment' => \App\Models\CustomPayment::class,
],
```

```php
// app/Models/CustomPayment.php
namespace App\Models;

use Ideacrafters\EloquentPayable\Traits\PaymentCapabilities;
use Illuminate\Database\Eloquent\Model;

class CustomPayment extends Model
{
    use PaymentCapabilities;
    
    // Your custom payment model with all lifecycle methods, 
    // status checks, and event emission automatically available
    // through the PaymentCapabilities trait
}
```

The `PaymentCapabilities` trait (which includes all child traits) provides:

**From `PaymentLifecycle` trait:**
- All lifecycle methods: `markAsPaid()`, `markAsFailed()`, `markAsCanceled()`, etc.
- All status check methods: `isCompleted()`, `isFailed()`, `isCanceled()`, etc.
- All query scopes: `scopeCompleted()`, `scopeFailed()`, `scopeCanceled()`, etc.
- Automatic timestamp management (via `bootPaymentLifecycle()`)

**From `InteractsWithPaymentEvents` trait:**
- Automatic event emission
- Event configuration checking (`shouldEmitEvents()`)

**From `InteractsWithPaymentProcessor` trait:**
- Processor interaction methods (`getProcessor()`, `isOffline()`)

**From `PaymentCapabilities` trait itself:**
- Payment relationships (`payer()`, `payable()`)

This is an internal change that doesn't affect your usage if you're using the default Payment model, but it significantly improves flexibility for custom implementations.

### Minor Payer Contract and Trait Improvements

Several minor improvements have been made to the `Payer` contract and `HasPayments` trait to provide better flexibility and robustness:

**New Methods in Payer Contract:**
- `getFirstName()` - Get the payer's first name
- `getLastName()` - Get the payer's last name
- `getBillingAddressAsString()` - Get billing address as a formatted string

**Default Implementations in HasPayments Trait:**
The `HasPayments` trait now provides default implementations for these new methods:

```php
// Default implementation in HasPayments trait
public function getFirstName(): ?string
{
    return property_exists($this, 'first_name') ? ($this->first_name ? (string) $this->first_name : null) : null;
}

public function getLastName(): ?string
{
    return property_exists($this, 'last_name') ? ($this->last_name ? (string) $this->last_name : null) : null;
}

public function getBillingAddressAsString(): string
{
    // First checks if billing_address is a string (use it directly)
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
        
        return implode(', ', $addressParts);
    }
    
    return '';
}
```

**Enhanced Phone Number Checking:**
The `getPhoneNumber()` method in the `HasPayments` trait now performs exhaustive property checking to improve flexibility:

```php
public function getPhoneNumber(): ?string
{
    // Checks multiple possible property names in order of preference
    if (property_exists($this, 'phone_number') && $this->phone_number) {
        return (string) $this->phone_number;
    }
    
    if (property_exists($this, 'phoneNumber') && $this->phoneNumber) {
        return (string) $this->phoneNumber;
    }
    
    if (property_exists($this, 'phonenumber') && $this->phonenumber) {
        return (string) $this->phonenumber;
    }
    
    if (property_exists($this, 'phone') && $this->phone) {
        return (string) $this->phone;
    }
    
    return null;
}
```

This ensures the method works with various naming conventions (`phone_number`, `phoneNumber`, `phonenumber`, `phone`) that might be used in different applications.

**Benefits:**
- **Flexibility**: Works with different property naming conventions
- **Robustness**: Handles missing properties gracefully
- **Consistency**: Standardized methods across all payer implementations
- **Backward Compatible**: Existing implementations continue to work

### Stripe Webhook Handler Refactoring

**Improvement:** Stripe webhook handling logic has been refactored into a separate `StripeWebhookHandler` class for better maintainability and readability.

**Before (Previous Structure):**
- Webhook handling logic was embedded directly in the `StripeProcessor` class
- All webhook event handlers were methods in the processor class
- Made the processor class larger and harder to maintain
- Difficult to extend or customize webhook handling

**After (Current Structure):**
- Webhook handling logic is now in a dedicated `StripeWebhookHandler` class
- `StripeProcessor` delegates webhook handling to the handler via service container
- Handler uses automatic event resolution that works with child classes
- Handler can be replaced in the service container for customization

**Key Features:**

1. **Automatic Event Resolution:**
   - Automatically resolves handler methods from event types
   - Example: `payment_intent.succeeded` → `handlePaymentIntentSucceeded()`
   - Works with child classes - if you extend `StripeWebhookHandler`, your custom handlers are automatically discovered

2. **Service Container Integration:**
   - Registered as a singleton in the service container
   - Can be rebound in your service provider to use a custom handler

3. **Idempotency:**
   - Built-in idempotency checking to prevent duplicate webhook processing
   - Configurable TTL via `payable.webhooks.event_idempotency_ttl_days`

**Using a Custom Webhook Handler:**

You can extend the handler and rebind it in your service provider:

```php
// app/Processors/CustomStripeWebhookHandler.php
namespace App\Processors;

use Ideacrafters\EloquentPayable\Processors\StripeWebhookHandler;

class CustomStripeWebhookHandler extends StripeWebhookHandler
{
    // Add custom event handlers
    protected function handleCheckoutSessionCompleted($checkoutSession)
    {
        // Your custom logic for checkout.session.completed
    }
    
    protected function handleInvoiceCreated($invoice)
    {
        // Your custom logic for invoice.created
    }
}
```

```php
// app/Providers/AppServiceProvider.php
use App\Processors\CustomStripeWebhookHandler;
use Ideacrafters\EloquentPayable\Processors\StripeWebhookHandler;

public function register()
{
    // Rebind the webhook handler to use your custom class
    $this->app->singleton(StripeWebhookHandler::class, function ($app) {
        return new CustomStripeWebhookHandler();
    });
}
```

**Benefits:**
- **Maintainability**: Webhook logic is separated from processor logic
- **Readability**: Cleaner, more focused classes
- **Extensibility**: Easy to extend with custom handlers
- **Flexibility**: Can be replaced via service container
- **Automatic Discovery**: Custom handlers in child classes are automatically found

### Processor Architecture Refactoring

**Important Internal Change:** The `BaseProcessor` class and its child classes have been restructured to ensure consistent and predictable behavior across all processors.

**Before (Previous Architecture):**
- Each processor class (Stripe, Slickpay, etc.) implemented their own validation logic
- Each processor class had to manually create payments and fire events
- This led to code duplication across processor classes
- Inconsistent validation and event emission could occur if not handled uniformly
- Risk of errors if developers forgot to fire events or perform validation

**After (Current Architecture):**
- `BaseProcessor` now controls all public-facing APIs (`process()`, `createRedirect()`, `refund()`, etc.)
- `BaseProcessor` handles all validation (payable, payer, amount, currency) uniformly
- `BaseProcessor` handles all event emissions consistently
- Child processors implement protected `do[Action]()` methods that contain only processor-specific logic:
  - `doProcess()` - Processor-specific payment processing
  - `doCreateRedirect()` - Processor-specific redirect creation
  - `doRefund()` - Processor-specific refund logic
  - `doCancel()` - Processor-specific cancellation logic

**Benefits:**
- **Consistent Validation**: All processors validate inputs the same way
- **Consistent Events**: Events fire uniformly across all processors
- **Reduced Duplication**: No repeated validation or event code
- **Predictable Behavior**: Same validation and event flow for all processors
- **Easier Maintenance**: Changes to validation or events happen in one place
- **Error Prevention**: Impossible to forget validation or events

**For Custom Processors:**

If you're creating custom processors, you now only need to implement the processor-specific logic:

```php
class MyCustomProcessor extends BaseProcessor
{
    // Only implement processor-specific logic
    protected function doProcess(Payment $payment, Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        // Your custom payment processing logic here
        // Validation and event emission are handled by BaseProcessor::process()
        
        $payment->update([
            'reference' => $this->createCustomReference(),
            'status' => PaymentStatus::processing(),
        ]);
        
        return $payment;
    }
    
    // Other protected methods as needed
    protected function doRefund(Payment $payment, ?float $amount = null): Payment
    {
        // Your custom refund logic
        return $payment;
    }
}
```

**What Changed:**
- Public methods (`process()`, `createRedirect()`, etc.) were previously abstract in `BaseProcessor`
- These methods now have concrete implementations that handle validation and event emission
- The implementations invoke protected `do[Action]()` methods in child processors for processor-specific logic
- Child processors only implement protected methods with processor-specific logic
- This ensures all processors behave consistently with uniform validation and event handling

## Rollback Plan

If you need to rollback:

### 1. Rollback Migration

```bash
php artisan migrate:rollback --step=1
```

This will remove the `canceled_at` column.

### 2. Revert Configuration

Remove the new configuration sections from `config/payable.php`:
- `statuses.canceled`
- `events` (if customized)
- `webhooks.event_idempotency_ttl_days` (if customized)

### 3. Update Code

Revert any code changes that use:
- `PaymentStatus::canceled()`
- `markAsCanceled()`
- `isCanceled()`
- New event configuration

## Support

If you encounter any issues during migration:

1. Check the [README.md](README.md) for updated documentation
2. Review the [CHANGELOG.md](CHANGELOG.md) for detailed changes
3. Open an issue on GitHub with details about your migration issue


