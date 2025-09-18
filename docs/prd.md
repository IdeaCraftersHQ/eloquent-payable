# Product Requirements Document (PRD)
## Laravel Eloquent Payable Package

### 1. Executive Summary

**Package Name:** `eloquent-payable`  
**Version:** 1.0.0  
**Purpose:** A Laravel package that enables any Eloquent model to accept payments by adding a simple trait, following Laravel's philosophy of expressive, elegant syntax.

The package provides a clean, configurable solution for adding payment capabilities to any Laravel application without requiring complex payment systems or tightly-coupled processor integrations. It works for any payment scenario: products, invoices, subscriptions, fees, fines, donations, or services.

### 2. Problem Statement

Laravel developers currently implement custom payment logic for each model that needs payment processing, resulting in:
- Duplicated payment processing code across projects
- Tight coupling between models and payment providers
- Inconsistent payment tracking and reporting
- Complex integration when switching payment processors
- No standardized way to handle offline payments
- Limited flexibility for different payment scenarios (invoices, fees, subscriptions)

### 3. Goals and Objectives

#### Primary Goals
- Provide a trait-based API that makes any Eloquent model payable with one line of code
- Support multiple payment processors with a swappable interface
- Enable simple offline payment tracking
- Prevent naming conflicts through configurable models and tables
- Deliver production-ready Stripe integration out of the box
- Support diverse payment scenarios beyond traditional e-commerce

#### Success Metrics
- Implementation time reduced from hours to minutes
- Zero breaking changes when switching payment processors
- Complete payment history tracking with minimal configuration
- Support for both online and offline payment workflows
- Flexibility to handle any payment scenario (products, invoices, fees, services)

### 4. Target Users

#### Primary Users
- Laravel developers building payment features
- SaaS applications requiring payment for features/subscriptions
- Invoice and billing systems
- Marketplace platforms needing flexible payment options
- Internal tools requiring payment tracking
- Service businesses handling various payment types

#### User Personas

**SaaS Developer Sarah**
- Building subscription-based software
- Needs simple Stripe integration
- Wants to focus on business logic, not payment processing

**Agency Developer Mike**
- Managing multiple client projects
- Needs configurable solution to avoid conflicts
- Requires support for different payment methods per project
- Handles both products and service invoices

**Enterprise Developer Lisa**
- Building internal finance system
- Needs offline payment tracking
- Requires detailed payment history and reporting
- Manages invoices, fees, and reimbursements

**Freelancer Developer Alex**
- Building custom billing systems
- Needs to handle various payment types (invoices, deposits, milestones)
- Requires flexible payment tracking

### 5. Functional Requirements

#### 5.1 Core Trait Functionality

**Payable Trait**
- `pay($payer, $amount, $options)` - Process a payment
- `payOffline($payer, $amount, $options)` - Create offline payment
- `getPayableAmount($payer)` - Calculate payment amount
- `isPayableBy($payer)` - Validate payment eligibility
- `payments()` - Relationship to payment history
- `pendingPayments()` - Query pending payments
- `refund($payment, $amount)` - Process refunds
- `getPaymentUrls($payment)` - Get callback/webhook URLs

#### 5.2 Payment Processors

**Interface Requirements**
- `process($payable, $payer, $amount, $options)`
- `refund($payment, $amount)`
- `handleWebhook($payload)`

**Built-in Processors**
1. **StripeProcessor**
   - Payment intent creation
   - Immediate charging with payment method
   - Webhook handling for async payments
   - Refund support
   - Signature verification

2. **OfflineProcessor**
   - Reference number generation
   - Pending/completed status management
   - Payment type tracking (cash, bank transfer, check, wire)
   - Manual payment confirmation
   - Note/metadata storage

3. **NoProcessor**
   - Free item handling
   - Immediate completion
   - No external dependencies

#### 5.3 Payment Model

**Fields**
- `payer_type`, `payer_id` - Polymorphic payer relationship
- `payable_type`, `payable_id` - Polymorphic payable item relationship
- `amount` - Decimal (10,2)
- `currency` - String (3)
- `status` - String (pending/processing/completed/failed/refunded/partially_refunded)
- `processor` - String (stripe/offline/none)
- `reference` - String (processor reference ID)
- `metadata` - JSON
- `refunded_amount` - Decimal (10,2) nullable
- `notes` - Text nullable
- `paid_at` - Timestamp nullable
- `failed_at` - Timestamp nullable
- `timestamps`

**Methods**
- `markAsPaid($paidAt)` - Mark offline payment as paid
- `markAsPending()` - Reset to pending status
- `markAsFailed($reason)` - Mark as failed with reason
- `isOffline()` - Check if offline payment
- `isCompleted()` - Check if payment completed
- `isPending()` - Check if payment pending
- `getProcessor()` - Get processor instance
- Scopes: `completed()`, `pending()`, `failed()`, `offline()`, `today()`, `thisMonth()`

#### 5.4 Configuration

**Configurable Elements**
- Database table names
- Model class names
- Amount column mapping
- Default currency
- Payment processor selection
- Route prefixes and middleware
- Webhook settings
- Payment statuses
- Decimal precision

#### 5.5 Routes and Webhooks

**Automatic Routes** (optional)
- `POST /payable/webhooks/stripe` - Stripe webhook handler
- `GET /payable/callback/success` - Success callback
- `GET /payable/callback/cancel` - Cancel callback
- `GET /payable/callback/failed` - Failed callback

**Route Features**
- Configurable prefix and middleware
- CSRF exemption for webhooks
- Signature verification
- Disable option for custom implementations

#### 5.6 Events

**Payment Events**
- `PaymentCreated`
- `PaymentCompleted`
- `PaymentFailed`
- `PaymentRefunded`
- `PaymentPartiallyRefunded`
- `OfflinePaymentCreated`
- `OfflinePaymentConfirmed`
- `PaymentCallbackReceived`

### 6. Non-Functional Requirements

#### 6.1 Performance
- Minimize database queries with eager loading
- Efficient webhook processing under 200ms
- Support for high-volume payments (1000+ per minute)
- Indexed database columns for reporting queries

#### 6.2 Security
- Webhook signature verification
- CSRF protection for web routes
- SQL injection prevention via Eloquent
- Safe metadata storage in JSON
- PCI compliance through tokenization
- Secure reference generation

#### 6.3 Compatibility
- Laravel 8.0+ support
- PHP 8.0+ requirement
- MySQL 5.7+, PostgreSQL 10+, SQLite 3.8.8+
- Stripe API v2020-08-27+
- Compatible with Laravel Cashier (no conflicts)

#### 6.4 Extensibility
- Custom payment processor interface
- Event-driven architecture
- Method overrides for custom logic
- Configurable without forking
- Middleware injection points
- Custom validation rules

### 7. Technical Architecture

#### 7.1 Package Structure
```
eloquent-payable/
├── config/
│   └── payable.php
├── database/
│   └── migrations/
│       └── create_payable_tables.php
├── routes/
│   └── payable.php
├── src/
│   ├── Contracts/
│   │   ├── PaymentProcessor.php
│   │   └── Payable.php
│   ├── Events/
│   │   ├── PaymentCompleted.php
│   │   ├── PaymentFailed.php
│   │   └── ...
│   ├── Exceptions/
│   │   ├── PaymentException.php
│   │   └── InvalidPayerException.php
│   ├── Http/
│   │   └── Controllers/
│   │       ├── WebhookController.php
│   │       └── CallbackController.php
│   ├── Models/
│   │   └── Payment.php
│   ├── Processors/
│   │   ├── BaseProcessor.php
│   │   ├── StripeProcessor.php
│   │   ├── OfflineProcessor.php
│   │   └── NoProcessor.php
│   ├── Traits/
│   │   ├── Payable.php
│   │   └── HasPayments.php
│   └── PayableServiceProvider.php
├── tests/
├── composer.json
└── README.md
```

#### 7.2 Database Schema
```sql
CREATE TABLE payments (
    id BIGINT UNSIGNED PRIMARY KEY,
    payer_type VARCHAR(255),
    payer_id BIGINT UNSIGNED,
    payable_type VARCHAR(255),
    payable_id BIGINT UNSIGNED,
    amount DECIMAL(10,2),
    currency VARCHAR(3),
    status VARCHAR(50),
    processor VARCHAR(50),
    reference VARCHAR(255),
    metadata JSON,
    refunded_amount DECIMAL(10,2) NULL,
    notes TEXT NULL,
    paid_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_payer (payer_type, payer_id),
    INDEX idx_payable (payable_type, payable_id),
    INDEX idx_status (status),
    INDEX idx_processor (processor),
    INDEX idx_reference (reference),
    INDEX idx_paid_at (paid_at),
    INDEX idx_created_at (created_at)
);
```

### 8. Implementation Plan

#### Phase 1: Core Package (Week 1)
- [x] Payable trait
- [x] Payment model
- [x] Base processor abstract class
- [x] Configuration structure
- [x] Service provider
- [x] Database migrations
- [x] Basic tests

#### Phase 2: Payment Processors (Week 2)
- [x] Stripe processor with webhooks
- [x] Offline processor
- [x] No processor (free items)
- [x] Webhook signature verification
- [x] Event system
- [x] Processor tests

#### Phase 3: Routes & Controllers (Week 3)
- [x] Automatic route registration
- [x] Webhook controller
- [x] Callback controller
- [x] CSRF exemption
- [x] Artisan commands
- [x] Route tests

#### Phase 4: Testing & Documentation (Week 4)
- [ ] Unit tests for traits
- [ ] Integration tests for processors
- [ ] Webhook testing utilities
- [ ] README documentation
- [ ] Wiki documentation
- [ ] Example application

#### Phase 5: Subscriptions (Week 5-6)
- [ ] Subscribable trait
- [ ] Subscription model
- [ ] Recurring payment logic
- [ ] Trial periods
- [ ] Plan changes
- [ ] Subscription tests

#### Phase 6: Advanced Features (Future)
- [ ] PayPal processor
- [ ] Partial payments
- [ ] Payment splitting
- [ ] Escrow support
- [ ] Multi-currency support
- [ ] Admin UI package
- [ ] Reporting dashboard

### 9. API Examples

#### Basic Usage
```php
// Model Setup
class Invoice extends Model
{
    use Payable;
    
    public function getPayableAmount($payer = null)
    {
        return $this->total_amount;
    }
}

class Product extends Model
{
    use Payable;
    
    public function getPayableAmount($payer = null)
    {
        // Dynamic pricing
        if ($payer && $payer->is_premium) {
            return $this->price * 0.9;
        }
        return $this->price;
    }
}

// Process Payments
$invoice->pay($client, $invoice->total_amount, [
    'processor' => 'stripe',
    'payment_method_id' => 'pm_card_visa'
]);

$product->pay($customer, $product->price * 2); // Pay for 2 items

// Offline Payment
$invoice->payOffline($client, $invoice->total_amount, [
    'type' => 'bank_transfer',
    'reference' => 'INV-2024-001'
]);

// Mark as Paid
$payment = $invoice->payments()->pending()->first();
$payment->markAsPaid();

// Refund
$payment->refund(50.00); // Partial refund
$payment->refund(); // Full refund
```

#### Different Payment Scenarios
```php
// E-commerce Product
$product->pay($customer, $product->price * $quantity);

// Service Invoice
$invoice->pay($client);

// Subscription Fee
$subscription->pay($subscriber, $subscription->monthly_fee);

// Fine/Penalty
$fine->pay($violator);

// Donation
$campaign->pay($donor, 100.00);

// Deposit
$booking->pay($customer, $booking->deposit_amount);

// Milestone Payment
$project->pay($client, $milestone->amount);
```

#### Configuration
```php
// config/payable.php
return [
    'default_processor' => env('PAYABLE_PROCESSOR', StripeProcessor::class),
    
    'processors' => [
        'stripe' => StripeProcessor::class,
        'offline' => OfflineProcessor::class,
        'none' => NoProcessor::class,
    ],
    
    'tables' => [
        'payments' => 'payments',
        'subscriptions' => 'subscriptions', // Future
    ],
    
    'models' => [
        'payment' => App\Models\Payment::class,
    ],
    
    'currency' => 'USD',
    'decimal_precision' => 2,
    
    'routes' => [
        'enabled' => true,
        'prefix' => 'payable',
        'middleware' => ['web'],
    ],
    
    'statuses' => [
        'pending' => 'pending',
        'processing' => 'processing',
        'completed' => 'completed',
        'failed' => 'failed',
        'refunded' => 'refunded',
        'partially_refunded' => 'partially_refunded',
    ],
];
```

#### Payer Trait (for User model)
```php
trait HasPayments
{
    public function payments()
    {
        return $this->morphMany(Payment::class, 'payer');
    }
    
    public function completedPayments()
    {
        return $this->payments()->completed();
    }
    
    public function pendingPayments()
    {
        return $this->payments()->pending();
    }
    
    public function paymentFor($payable)
    {
        return $this->payments()
            ->where('payable_type', get_class($payable))
            ->where('payable_id', $payable->id)
            ->latest()
            ->first();
    }
}

// Usage
class User extends Authenticatable
{
    use HasPayments;
}

$user->payments; // All payments made by user
$user->completedPayments; // Only completed payments
$user->paymentFor($invoice); // Specific payment
```

### 10. Testing Strategy

#### Unit Tests
```php
class PayableTest extends TestCase
{
    /** @test */
    public function model_can_accept_payments()
    {
        $invoice = Invoice::factory()->create(['amount' => 100]);
        $user = User::factory()->create();
        
        $payment = $invoice->pay($user, 100);
        
        $this->assertEquals('completed', $payment->status);
        $this->assertEquals(100, $payment->amount);
    }
    
    /** @test */
    public function can_process_offline_payments()
    {
        $invoice = Invoice::factory()->create();
        
        $payment = $invoice->payOffline($user, 100, [
            'type' => 'check',
            'reference' => 'CHK-123'
        ]);
        
        $this->assertEquals('pending', $payment->status);
        $this->assertEquals('offline', $payment->processor);
    }
}
```

#### Integration Tests
- Payment processor flow
- Webhook handling
- Event dispatching
- Database transactions
- Refund processing

#### End-to-End Tests
- Complete payment flow
- Webhook callbacks
- Offline payment confirmation
- Multi-processor switching

### 11. Documentation Requirements

#### User Documentation
- Installation guide
- Quick start tutorial
- Configuration reference
- Payment processor setup (Stripe, Offline)
- Webhook configuration
- Common recipes
- FAQ and troubleshooting

#### API Documentation
- Trait methods
- Model methods
- Event reference
- Processor interface
- Configuration options

#### Example Implementations
- E-commerce product payments
- Invoice payment system
- Subscription billing
- Fee collection system
- Donation platform
- Service booking deposits

### 12. Success Criteria

- ✅ Any Eloquent model can become payable with one trait
- ✅ Support for multiple payment processors
- ✅ Zero configuration required for basic usage
- ✅ Full configuration available for advanced usage
- ✅ Offline payment tracking
- ✅ Complete payment history
- ✅ Production-ready Stripe integration
- ✅ No naming conflicts with existing models
- ✅ Comprehensive event system
- ✅ Support for diverse payment scenarios
- ✅ Well-documented and tested

### 13. Risk Analysis

#### Technical Risks
- **Payment processor API changes** - Mitigated by abstraction layer
- **Database performance** - Mitigated by proper indexing
- **Webhook reliability** - Mitigated by idempotent handling
- **Currency precision** - Mitigated by decimal types

#### Business Risks
- **PCI compliance** - Package doesn't handle card data directly
- **Currency conversion** - Delegated to payment processors
- **Tax calculation** - Outside current scope
- **Regulatory compliance** - Document requirements clearly

### 14. Future Enhancements

#### Version 1.1 - Subscriptions
- Subscribable trait
- Recurring payments
- Trial periods
- Plan management
- Subscription lifecycle

#### Version 1.2 - Advanced Payments
- Partial payments
- Payment plans/installments
- Split payments
- Escrow support
- Hold and capture

#### Version 1.3 - Multi-currency
- Currency conversion
- Multi-currency accounts
- Exchange rate management
- Localized payment methods

#### Version 2.0 - Platform Features
- Multi-vendor support
- Commission management
- Marketplace features
- Advanced reporting dashboard
- Admin UI package

### 15. Competitive Analysis

#### Advantages Over Alternatives

**vs Laravel Cashier**
- Not limited to subscriptions
- Works with any model, not just User
- Supports offline payments
- More flexible payment scenarios

**vs Custom Solutions**
- Standardized approach
- Battle-tested integrations
- Comprehensive documentation
- Active maintenance

**vs Full E-commerce Packages**
- Lightweight and focused
- No opinionated UI
- Easy to integrate
- Minimal dependencies

### 16. Performance Benchmarks

#### Target Metrics
- Payment creation: < 50ms
- Webhook processing: < 200ms
- Payment query: < 10ms
- Bulk operations: 1000+ payments/minute
- Memory usage: < 10MB per request

### 17. Security Considerations

#### Payment Security
- No direct card handling
- Token-based processing
- Webhook signature verification
- Rate limiting on endpoints
- Audit logging for all payments

#### Data Protection
- Encrypted metadata storage option
- PII handling guidelines
- GDPR compliance features
- Data retention policies

### 18. Appendix

#### Glossary
- **Payable**: Any model that can accept payments
- **Payer**: Entity making a payment
- **Processor**: Payment gateway or method
- **Reference**: External payment identifier

#### Dependencies
- Laravel Framework 8.0+
- PHP 8.0+
- Stripe PHP SDK (optional)
- OpenSSL for webhook verification

#### Standards
- PSR-12 coding standard
- Laravel package conventions
- Semantic versioning
- ISO 4217 currency codes

---

*This PRD represents version 1.0.0 of the eloquent-payable package. It will be updated based on community feedback and evolving requirements.*