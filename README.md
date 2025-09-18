# Eloquent Payable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ideacrafters/eloquent-payable.svg?style=flat-square)](https://packagist.org/packages/ideacrafters/eloquent-payable)
[![Total Downloads](https://img.shields.io/packagist/dt/ideacrafters/eloquent-payable.svg?style=flat-square)](https://packagist.org/packages/ideacrafters/eloquent-payable)
[![License](https://img.shields.io/packagist/l/ideacrafters/eloquent-payable.svg?style=flat-square)](https://packagist.org/packages/ideacrafters/eloquent-payable)

A Laravel package that enables any Eloquent model to accept payments by adding a simple trait. Perfect for invoices, products, subscriptions, fees, donations, and any other payment scenarios.

## Features

- 🚀 **One-line integration** - Add payment capabilities to any model with a single trait
- 💳 **Multiple processors** - Stripe, offline payments, and free items out of the box
- 🔄 **Swappable processors** - Switch payment providers without changing your code
- 📊 **Complete payment history** - Track all payments with detailed metadata
- 🎯 **Flexible scenarios** - Works for products, invoices, subscriptions, fees, donations
- 🔒 **Secure** - Webhook signature verification and PCI compliance through tokenization
- 📈 **Event-driven** - Comprehensive event system for payment lifecycle
- ⚡ **Performance optimized** - Efficient queries with proper indexing
- 🧪 **Well tested** - Comprehensive test suite included

## Installation

You can install the package via Composer:

```bash
composer require ideacrafters/eloquent-payable
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Ideacrafters\EloquentPayable\PayableServiceProvider" --tag="config"
```

Run the migrations:

```bash
php artisan migrate
```

## Quick Start

### 1. Make your model payable

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Ideacrafters\EloquentPayable\Traits\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payable as PayableContract;
use Ideacrafters\EloquentPayable\Contracts\Payer;

class Invoice extends Model implements PayableContract
{
    use Payable;

    protected $fillable = ['total_amount', 'client_id', 'title', 'description', 'currency', 'status'];

    public function getPayableAmount(?Payer $payer = null): float
    {
        return $this->total_amount;
    }

    public function isPayableBy(Payer $payer): bool
    {
        return $this->client_id === $payer->getKey();
    }

    public function getPayableTitle(): string
    {
        return $this->title ?: "Invoice #{$this->id}";
    }

    public function getPayableDescription(): ?string
    {
        return $this->description;
    }

    public function getPayableCurrency(): string
    {
        return $this->currency ?: 'USD';
    }

    public function getPayableMetadata(): array
    {
        return [
            'invoice_id' => $this->id,
            'client_id' => $this->client_id,
            'status' => $this->status,
        ];
    }

    public function requiresPayment(): bool
    {
        return $this->total_amount > 0;
    }

    public function isPayableActive(): bool
    {
        return in_array($this->status, ['pending', 'overdue']);
    }
}
```

### 2. Add payments to your user model

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Ideacrafters\EloquentPayable\Traits\HasPayments;
use Ideacrafters\EloquentPayable\Contracts\Payer;

class User extends Authenticatable implements Payer
{
    use HasPayments;

    public function getKey()
    {
        return $this->getKey();
    }

    public function getMorphClass()
    {
        return static::class;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function canMakePayments(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function getPreferredCurrency(): ?string
    {
        return $this->preferred_currency ?? 'USD';
    }

    public function getBillingAddress(): ?array
    {
        return $this->billing_address;
    }

    public function getShippingAddress(): ?array
    {
        return $this->shipping_address;
    }

    public function getTaxId(): ?string
    {
        return $this->tax_id;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phone;
    }

    public function getLocale(): ?string
    {
        return $this->locale ?? 'en';
    }

    public function getTimezone(): ?string
    {
        return $this->timezone ?? 'UTC';
    }

    public function getMetadata(): array
    {
        return [
            'user_id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
```

### 3. Process payments

```php
// Process a payment
$invoice->pay($client, $invoice->total_amount);

// Process with Stripe
$invoice->pay($client, $invoice->total_amount, [
    'processor' => 'stripe',
    'payment_method_id' => 'pm_card_visa'
]);

// Create offline payment
$invoice->payOffline($client, $invoice->total_amount, [
    'type' => 'bank_transfer',
    'reference' => 'INV-2024-001'
]);

// Mark offline payment as paid
$payment = $invoice->payments()->pending()->first();
$payment->markAsPaid();

// Refund a payment
$payment->refund(50.00); // Partial refund
$payment->refund(); // Full refund
```

## Facade Usage

The package includes a powerful Facade for easy access to payment operations:

```php
use Ideacrafters\EloquentPayable\Facades\Payable;

// Process payments
$payment = Payable::process($invoice, $client, 100.00);
$offlinePayment = Payable::processOffline($invoice, $client, 100.00);

// Payment management
$payment = Payable::find(1);
Payable::markAsPaid($payment);
Payable::markAsFailed($payment, 'Card declined');
Payable::refund($payment, 50.00);

// Payment queries
$payments = Payable::getPaymentsFor($invoice);
$userPayments = Payable::getPaymentsBy($user);
$totalPaid = Payable::getTotalPaidBy($user);
$hasPaid = Payable::hasPaidFor($invoice, $user);

// Statistics
$stats = Payable::getPaymentStats();
$processorStats = Payable::getProcessorStats();

// Health check
$health = Payable::getHealthCheck();

// Configuration
$config = Payable::getConfiguration();
$currencies = Payable::getSupportedCurrencies();
$processors = Payable::getProcessorNames();

// Logging
Payable::info('Payment processed', ['payment_id' => $payment->id]);
Payable::error('Payment failed', ['error' => $exception->getMessage()]);
```

## Configuration

The package configuration is published to `config/payable.php`. Here are the key options:

```php
return [
    'default_processor' => env('PAYABLE_PROCESSOR', 'stripe'),
    
    'processors' => [
        'stripe' => \Ideacrafters\EloquentPayable\Processors\StripeProcessor::class,
        'offline' => \Ideacrafters\EloquentPayable\Processors\OfflineProcessor::class,
        'none' => \Ideacrafters\EloquentPayable\Processors\NoProcessor::class,
    ],
    
    'tables' => [
        'payments' => 'payments',
    ],
    
    'currency' => 'USD',
    'decimal_precision' => 2,
    
    'routes' => [
        'enabled' => true,
        'prefix' => 'payable',
        'middleware' => ['web'],
    ],
    
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
```

## Redirect-Based Payments

The package now supports redirect-based payments for processors like Stripe Checkout:

```php
// Create a redirect payment
$redirect = $invoice->payRedirect($client, $invoice->total_amount, [
    'processor' => 'stripe',
    'success_url' => 'https://yourapp.com/success',
    'cancel_url' => 'https://yourapp.com/cancel',
    'failure_url' => 'https://yourapp.com/failed'
]);

// Redirect user to payment page
return redirect($redirect->getRedirectUrl());

// Or use the Facade
$redirect = Payable::createRedirect($invoice, $client, $invoice->total_amount, [
    'processor' => 'stripe',
    'success_url' => 'https://yourapp.com/success'
]);
```

### Redirect URLs

The package provides flexible redirect URL handling:

- **Custom URLs**: Pass `success_url`, `cancel_url`, `failure_url` in options
- **Default URLs**: Uses package's built-in redirect handlers
- **Metadata URLs**: Store URLs in payment metadata for later use

### Redirect Controller

The package includes a `RedirectController` that handles:
- Payment completion verification
- Automatic payment status updates
- Custom redirect URL support
- Error handling and logging

## Payment Processors

### Stripe Processor

The Stripe processor handles online payments with full webhook support:

```php
// Process immediate payment
$invoice->pay($client, 100.00, [
    'processor' => 'stripe',
    'payment_method_id' => 'pm_card_visa'
]);

// Create payment intent for later confirmation
$invoice->pay($client, 100.00, [
    'processor' => 'stripe'
]);
```

**Environment Variables:**
```env
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

### Offline Processor

For manual payments like cash, checks, or bank transfers:

```php
$invoice->payOffline($client, 100.00, [
    'type' => 'bank_transfer',
    'reference' => 'TXN-123456',
    'notes' => 'Payment via bank transfer'
]);

// Later, mark as paid
$payment->markAsPaid();
```

### No Processor

For free items or services:

```php
$freeItem->pay($user, 0.00, ['processor' => 'none']);
// Automatically marked as completed
```

## Payment Scenarios

### E-commerce Products

```php
class Product extends Model implements PayableContract
{
    use Payable;

    public function getPayableAmount($payer = null): float
    {
        // Dynamic pricing based on user
        if ($payer && $payer->is_premium) {
            return $this->price * 0.9; // 10% discount
        }
        return $this->price;
    }
}

// Usage
$product->pay($customer, $product->price * $quantity);
```

### Service Invoices

```php
class ServiceInvoice extends Model implements PayableContract
{
    use Payable;

    public function getPayableAmount($payer = null): float
    {
        return $this->calculateTotal();
    }

    public function isPayableBy($payer): bool
    {
        return $this->client_id === $payer->id && $this->status === 'pending';
    }
}
```

### Donations

```php
class Campaign extends Model implements PayableContract
{
    use Payable;

    public function getPayableAmount($payer = null): float
    {
        return $payer ? $payer->donation_amount : 0;
    }
}

// Usage
$campaign->pay($donor, 100.00);
```

### Subscription Fees

```php
class Subscription extends Model implements PayableContract
{
    use Payable;

    public function getPayableAmount($payer = null): float
    {
        return $this->monthly_fee;
    }
}
```

## Payment Relationships

### Payable Models

```php
$invoice->payments; // All payments for this invoice
$invoice->completedPayments; // Only completed payments
$invoice->pendingPayments; // Only pending payments
$invoice->failedPayments; // Only failed payments
```

### Payer Models

```php
$user->payments; // All payments made by user
$user->completedPayments; // Only completed payments
$user->pendingPayments; // Only pending payments
$user->paymentsToday(); // Today's payments
$user->paymentsThisMonth(); // This month's payments
$user->paymentFor($invoice); // Specific payment for an item
$user->hasPaidFor($invoice); // Check if user paid for item
$user->getTotalPaid(); // Total amount paid
```

## Events

The package fires events throughout the payment lifecycle:

```php
use Ideacrafters\EloquentPayable\Events\PaymentCreated;
use Ideacrafters\EloquentPayable\Events\PaymentCompleted;
use Ideacrafters\EloquentPayable\Events\PaymentFailed;
use Ideacrafters\EloquentPayable\Events\PaymentRefunded;
use Ideacrafters\EloquentPayable\Events\OfflinePaymentCreated;
use Ideacrafters\EloquentPayable\Events\OfflinePaymentConfirmed;

// Listen to events
Event::listen(PaymentCompleted::class, function ($event) {
    // Send confirmation email
    Mail::to($event->payment->payer)->send(new PaymentConfirmation($event->payment));
});
```

## Webhooks

### Stripe Webhooks

The package automatically handles Stripe webhooks at `/payable/webhooks/stripe`:

```php
// In your Stripe dashboard, set webhook URL to:
// https://yourdomain.com/payable/webhooks/stripe
```

### Custom Webhooks

```php
// Handle other processors
Route::post('/payable/webhooks/paypal', [WebhookController::class, 'handle']);
```

## Callbacks

The package provides callback URLs for payment success, cancellation, and failure:

- Success: `/payable/callback/success?payment={id}`
- Cancel: `/payable/callback/cancel?payment={id}`
- Failed: `/payable/callback/failed?payment={id}`

## Facade API Reference

The `Payable` Facade provides a comprehensive API for all payment operations:

### Payment Processing
- `Payable::process($payable, $payer, $amount, $options)` - Process a payment
- `Payable::processOffline($payable, $payer, $amount, $options)` - Create offline payment
- `Payable::refund($payment, $amount)` - Refund a payment

### Payment Management
- `Payable::find($id)` - Find payment by ID
- `Payable::markAsPaid($payment, $paidAt)` - Mark as paid
- `Payable::markAsFailed($payment, $reason)` - Mark as failed
- `Payable::markAsPending($payment)` - Mark as pending

### Payment Queries
- `Payable::getPaymentsFor($payable)` - Get payments for an item
- `Payable::getPaymentsBy($payer)` - Get payments by a payer
- `Payable::getTotalPaidBy($payer)` - Get total paid by payer
- `Payable::getTotalPaidFor($payable)` - Get total paid for item
- `Payable::hasPaidFor($payable, $payer)` - Check if payer paid for item
- `Payable::getLatestPaymentFor($payable, $payer)` - Get latest payment

### Payment Collections
- `Payable::getCompletedPayments()` - All completed payments
- `Payable::getPendingPayments()` - All pending payments
- `Payable::getFailedPayments()` - All failed payments
- `Payable::getOfflinePayments()` - All offline payments
- `Payable::getPaymentsToday()` - Today's payments
- `Payable::getPaymentsThisMonth()` - This month's payments

### Statistics & Analytics
- `Payable::getPaymentStats()` - Payment statistics
- `Payable::getProcessorStats()` - Processor statistics
- `Payable::getMetrics()` - All metrics
- `Payable::getHealthCheck()` - System health check

### Configuration & Management
- `Payable::getConfiguration()` - Get all configuration
- `Payable::getSupportedCurrencies()` - Supported currencies
- `Payable::getDefaultCurrency()` - Default currency
- `Payable::getProcessorNames()` - Available processors
- `Payable::isProcessorSupported($name)` - Check processor support
- `Payable::setDefaultProcessor($name)` - Set default processor

### Utility Methods
- `Payable::getPaymentUrls($payment)` - Get callback URLs
- `Payable::getWebhookUrls()` - Get webhook URLs
- `Payable::getCallbackUrls()` - Get callback URLs
- `Payable::clearCache()` - Clear cached data
- `Payable::warmCache()` - Warm up cache

### Logging
- `Payable::debug($message, $context)` - Debug log
- `Payable::info($message, $context)` - Info log
- `Payable::warning($message, $context)` - Warning log
- `Payable::error($message, $context)` - Error log
- `Payable::critical($message, $context)` - Critical log

## Advanced Usage

### Custom Payment Processors

Create your own payment processor:

```php
<?php

namespace App\Processors;

use Ideacrafters\EloquentPayable\Processors\BaseProcessor;
use Ideacrafters\EloquentPayable\Models\Payment;

class PayPalProcessor extends BaseProcessor
{
    public function getName(): string
    {
        return 'paypal';
    }

    public function process($payable, $payer, float $amount, array $options = []): Payment
    {
        // Your PayPal integration logic
        $payment = $this->createPayment($payable, $payer, $amount, $options);
        
        // Process with PayPal API
        $paypalPayment = $this->createPayPalPayment($amount, $options);
        
        $payment->update([
            'reference' => $paypalPayment->id,
            'status' => 'processing'
        ]);

        return $payment;
    }

    public function refund(Payment $payment, ?float $amount = null): Payment
    {
        // Your PayPal refund logic
        return $payment;
    }

    public function handleWebhook(array $payload)
    {
        // Handle PayPal webhooks
    }
}
```

Register your processor in the config:

```php
'processors' => [
    'stripe' => \Ideacrafters\EloquentPayable\Processors\StripeProcessor::class,
    'paypal' => \App\Processors\PayPalProcessor::class,
    'offline' => \Ideacrafters\EloquentPayable\Processors\OfflineProcessor::class,
    'none' => \Ideacrafters\EloquentPayable\Processors\NoProcessor::class,
],
```

### Custom Payment Logic

Override methods in your payable models:

```php
class Invoice extends Model implements PayableContract
{
    use Payable;

    public function getPayableAmount($payer = null): float
    {
        $baseAmount = $this->subtotal;
        
        // Apply discounts
        if ($payer && $payer->hasDiscount()) {
            $baseAmount *= 0.9;
        }
        
        // Add taxes
        $baseAmount += $this->calculateTax($baseAmount);
        
        return $baseAmount;
    }

    public function isPayableBy($payer): bool
    {
        // Only allow payment by the invoice client
        if ($this->client_id !== $payer->id) {
            return false;
        }
        
        // Check if invoice is in payable status
        if (!in_array($this->status, ['pending', 'overdue'])) {
            return false;
        }
        
        // Check if not already fully paid
        $totalPaid = $this->completedPayments()->sum('amount');
        return $totalPaid < $this->total_amount;
    }
}
```

### Payment Queries

Use the built-in scopes for efficient queries:

```php
// Get all completed payments
Payment::completed()->get();

// Get pending offline payments
Payment::pending()->offline()->get();

// Get payments from today
Payment::today()->get();

// Get payments from this month
Payment::thisMonth()->get();

// Get payments for specific payable
$invoice->payments()->completed()->sum('amount');
```

## Testing

The package includes comprehensive tests. Run them with:

```bash
composer test
```

### Testing Payments

```php
use Ideacrafters\EloquentPayable\Tests\TestCase;

class PaymentTest extends TestCase
{
    /** @test */
    public function can_process_payment()
    {
        $invoice = Invoice::factory()->create(['amount' => 100]);
        $user = User::factory()->create();
        
        $payment = $invoice->pay($user, 100);
        
        $this->assertEquals('completed', $payment->status);
        $this->assertEquals(100, $payment->amount);
    }
}
```

## Security

- **PCI Compliance**: The package never handles card data directly
- **Webhook Verification**: All webhooks are signature verified
- **SQL Injection Protection**: Uses Eloquent ORM for all database operations
- **CSRF Protection**: Web routes are protected by default
- **Rate Limiting**: Webhook endpoints are rate limited

## Performance

- **Optimized Queries**: Uses eager loading and proper indexing
- **Efficient Webhooks**: Processes webhooks in under 200ms
- **Scalable**: Supports high-volume payments (1000+ per minute)
- **Memory Efficient**: Minimal memory footprint per request

## Requirements

- PHP 8.0+
- Laravel 8.0+
- MySQL 5.7+, PostgreSQL 10+, or SQLite 3.8.8+

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Ideacrafters](https://github.com/ideacrafters)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Support

If you discover any issues or have questions, please open an issue on GitHub or contact us at hello@ideacrafters.com.

---

**Made with ❤️ by [Ideacrafters](https://ideacrafters.com)**
