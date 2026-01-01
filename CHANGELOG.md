# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.10] - 2025-12-28

### Fixed
- Add Null checks for metadata set from satim register order response

---

## [2.1.9] - 2025-12-28

### Changed
- Add more metadata fields (order_number, order_status, error_code, etc.) to SATIM response processing

---

## [2.1.8] - 2025-12-25

### Fixed
- Update SATIM response processing

---

## [2.1.7] - 2025-12-25

### Fixed
- Make isNotFinalStatus method public in PaymentLifecycle trait

---

## [2.1.6] - 2025-12-25

### Added
- Online payments scope in PaymentLifecycle trait

---

## [2.1.5] - 2025-12-25

### Fixed
- Register view namespace for payable package views
- Add default payment redirect views (success, cancel, failed, error)

---

## [2.1.4] - 2025-12-24

### Fixed
- SATIM response field name corrected from mdOrder to orderId

---

## [2.1.3] - 2025-12-24

### Fixed
- SATIM response object to array conversion for register and refund operations

---

## [2.1.2] - 2025-12-24

### Fixed
- SATIM amount conversion to centimes for payment and refund operations

---

## [2.1.1] - 2025-12-24

### Fixed
- Updated dependencies and fixed imports


---

## [2.1.0] - 2025-12-24

### Added
- SATIM payment processor integration with redirect-based payments
- Support for SATIM refunds
- SATIM configuration in `config/payable.php` with environment variable support
- SATIM processor registration in service provider

---

## [2.0.0] - 2024-01-02

### ⚠️ Breaking Changes

#### Processor Architecture Refactoring
- **BREAKING**: Custom processors must be refactored to use protected `do[Action]()` methods instead of public methods
- Public methods (`process()`, `createRedirect()`, `refund()`, `cancel()`) are now implemented in `BaseProcessor`
- Custom processors must implement protected methods: `doProcess()`, `doCreateRedirect()`, `doRefund()`, `doCancel()`
- Validation and event emission are now handled automatically by `BaseProcessor`
- See [Migration Guide](MIGRATION-GUIDE.md) for detailed refactoring instructions

#### Event System Changes
- **BREAKING**: Events are now automatically fired by the library - manual `event()` calls should be removed
- Events fire automatically from processors and lifecycle methods (`markAsPaid()`, `markAsFailed()`, etc.)
- Manual event firing is discouraged and may cause duplicate events
- Event configuration system introduced for global and per-processor control

#### Database Migration Required
- **BREAKING**: New migration adds `canceled_at` column to payments table
- Must run `php artisan migrate` to add the new column and index
- Migration file: `2024_01_02_000000_add_canceled_at_to_payments_table.php`

### Added

#### Payment Lifecycle
- Payment canceled state with `canceled` status
- `markAsCanceled()` method for canceling payments
- `isCanceled()` method and `canceled()` query scope
- `PaymentCanceled` event fired when payments are canceled
- Automatic `canceled_at` timestamp management

#### PaymentStatus Class
- New `PaymentStatus` class for centralized status access
- Static methods: `pending()`, `processing()`, `completed()`, `failed()`, `refunded()`, `partiallyRefunded()`, `canceled()`
- Recommended replacement for `Config::get('payable.statuses.*')`

#### Currency Validation
- Automatic currency validation per processor
- Stripe supports multiple currencies
- Slickpay restricted to DZD only
- Throws `PaymentException` for unsupported currencies

#### Processor Feature Detection
- `supportsCancellation()` method to check if processor supports payment cancellation
- `supportsRefunds()` method to check if processor supports refunds
- `supportsMultipleCurrencies()` method to check if processor supports multiple currencies

#### Event System Enhancements
- Automatic event firing system - events fire consistently across all payment operations
- Event configuration system with global and per-processor settings
- `payable.events.enabled` configuration for global event control
- `payable.events.processors` configuration for per-processor event control
- `shouldEmitEvents()` method for checking event configuration

#### Stripe Webhook Handler
- New `StripeWebhookHandler` class for better code organization
- Automatic event resolution from Stripe event types to handler methods
- Extensible via service container binding
- Built-in idempotency checking with configurable TTL
- `payable.webhooks.event_idempotency_ttl_days` configuration option

#### Payer Contract Enhancements
- `getFirstName()` method in `Payer` contract
- `getLastName()` method in `Payer` contract
- `getBillingAddressAsString()` method in `Payer` contract
- Enhanced `getPhoneNumber()` method with multiple property name support (`phone_number`, `phoneNumber`, `phonenumber`, `phone`)

### Changed

#### BaseProcessor Architecture
- Centralized validation logic (payable, payer, amount, currency) in `BaseProcessor`
- Centralized event emission in `BaseProcessor`
- Public methods now have concrete implementations that delegate to protected `do[Action]()` methods
- Consistent validation and event flow across all processors
- `processPaymentWithoutEvents()` method for internal use in redirect creation

#### Payment Model Refactoring
- Payment model refactored into reusable traits:
  - `PaymentCapabilities` - Main trait combining all sub-traits
  - `PaymentLifecycle` - Status management and lifecycle methods
  - `InteractsWithPaymentProcessor` - Processor access and offline checking
  - `InteractsWithPaymentEvents` - Event configuration and emission
- Enables custom payment models with full functionality via traits
- Improved code reusability and maintainability

#### Automatic Timestamp Management
- Automatic `paid_at` timestamp when status becomes `completed`
- Automatic `failed_at` timestamp when status becomes `failed`
- Automatic `canceled_at` timestamp when status becomes `canceled`
- Timestamps are mutually exclusive and automatically cleared on status changes
- No manual timestamp management required

#### HasPayments Trait Improvements
- Default implementations for `getFirstName()`, `getLastName()`, `getBillingAddressAsString()`
- Enhanced `getPhoneNumber()` with exhaustive property checking
- Better handling of missing properties and different naming conventions

### Deprecated

- `OfflinePaymentCreated` event - Use `PaymentCreated` with `isOffline` flag instead
- `OfflinePaymentConfirmed` event - Use `PaymentCompleted` instead
- Both events still work but emit deprecation warnings and will be removed in a future version

### Internal Improvements

- Improved code organization and maintainability
- Reduced code duplication across processors
- Better separation of concerns
- Enhanced extensibility for custom implementations
- More predictable and consistent behavior

---

## [1.0.0] - 2024-01-01

### Added
- Initial release of eloquent-payable package
- Payable trait for adding payment capabilities to any Eloquent model
- HasPayments trait for payer models
- Payment model with comprehensive payment tracking
- Stripe processor with webhook support
- Offline processor for manual payments
- No processor for free items
- Comprehensive event system
- Webhook and callback controllers
- Database migrations
- Configuration system
- Service provider with automatic route registration
- Comprehensive test suite
- Detailed documentation

### Features
- One-line integration with any Eloquent model
- Multiple payment processors (Stripe, Offline, None)
- Swappable processor architecture
- Complete payment history tracking
- Support for various payment scenarios (products, invoices, subscriptions, fees, donations)
- Webhook signature verification
- Event-driven architecture
- Performance optimized with proper indexing
- PCI compliance through tokenization
- Comprehensive API for payment management
