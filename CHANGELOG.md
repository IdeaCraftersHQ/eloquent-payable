# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.3.1] - 2026-08-04

### Fixed
- **SATIM amounts were double-converted to centimes, so the gateway received 100x the intended amount** ([#8](https://github.com/IdeaCraftersHQ/eloquent-payable/issues/8)). `SatimProcessor` converted DZD to centimes before calling `Satim::amount()` / `Satim::refund()`, but satim-laravel has owned that conversion since its v1.0.0. A `createRedirect(..., 2500.00, ...)` registered an order of 25,000,000 centimes and the hosted payment page displayed 250 000,00 DZD. Refunds were issued at 100x the paid amount and rejected by SATIM, so they could not succeed at all. The processor now passes dinars, as it did up to v2.1.1. Affected v2.1.2 through v2.3.0; introduced in `3513511`.

### Added
- `doCompleteRedirect()` now verifies SATIM's reported `depositAmount` / `amount` against the local `Payment` before marking it paid. On disagreement the payment is marked failed, the two figures are recorded under `metadata.amount_mismatch`, and a new `PaymentAmountMismatchException` is thrown rather than a wrong-magnitude capture being completed silently. Verification is skipped when SATIM reports no amount.

### Changed
- `SatimProcessor::convertToCents()` renamed to `toCentimes()` and repurposed for confirmation-time verification only. Deliberately renamed rather than kept: a subclass that overrode `convertToCents()` to compensate for the bug would otherwise have silently corrupted the new amount check.

### Upgrade notes
- No public API changes. Anyone on v2.1.2–v2.3.0 who was compensating by pre-dividing amounts by 100 must remove that workaround, including any subclass override of `convertToCents()`.

---

## [2.3.0] - 2026-06-17

### Added
- Laravel 13 support. `illuminate/*` constraints now allow `^13.0` alongside the existing 8–12 range. On a Laravel 13 project, Composer resolves `ideacrafters/satim-laravel` to its Laravel 13–compatible `^1.3` release automatically (the existing `^1.1` constraint already permits it).

### Changed
- Dev tooling extended to test against Laravel 13: `orchestra/testbench` now allows `^11.0` and `phpunit/phpunit` allows `^11.0|^12.0` (testbench 11 requires PHPUnit ≥ 11).

### Upgrade notes
- Fully backward compatible. No code or config changes required; the new constraints are purely additive.

---

## [2.2.0] - 2026-06-01

### Added
- Per-payment credential resolution via `PayableManager::resolveCredentialsFor(processor, closure)`. Hosts can now route each payment through a different SATIM or Slickpay merchant account by registering a `fn(Payment): ?array` resolver at boot. Returning `null` falls back to env credentials, so single-tenant deployments are unaffected.
- `CredentialBundle::forSatim()` and `::forSlickpay()` validate the all-or-nothing rule on required auth fields and throw `InvalidCredentialBundleException` on partial bundles.
- `merchant_pointer` is persisted into `payment.metadata` at create time so confirm and refund re-resolve the same tenant's credentials without depending on the host's relation chain remaining intact.

### Changed
- `SatimProcessor` no longer uses the `Satim` facade internally; constructs a fresh `SatimClient` + `Satim` per call from the resolved credential bundle.
- `SlickpayProcessor` builds its HTTP client per call with the resolved credentials; `getBaseUrl()` honors per-tenant `sandbox_mode`.

### Internal
- `PayableServiceProvider` aliases `PayableManager::class` to the `'payable'` singleton. Without the alias, processors looking up the registry via `app(PayableManager::class)` would get a different instance than the `Payable` facade.

### Upgrade notes
- Fully backward compatible. Existing projects need no code or config changes — when no resolver is registered, every payment resolves to your env/config credentials exactly as in 2.1.x. The new per-tenant routing only activates once you register a resolver via `PayableManager::resolveCredentialsFor()`.

---

## [2.1.14] - 2026-03-25

### Fixed
- `SatimProcessor::doCompleteRedirect()` now stores SATIM error context (actionCode, respCode, etc.) in payment metadata when `SatimException` is thrown, eliminating the need for app-level workarounds

### Added
- `SatimProcessor::getErrorDetailsForDisplay(Payment)` — returns normalized error details with fallback chain from confirmation response to flat metadata fields
- `Payment::getOrderNumberAttribute()` — accessor for `metadata.order_number`
- `Payment::getErrorCodeAttribute()` — accessor for `metadata.error_code`
- `Payment::getErrorDescriptionAttribute()` — accessor for `metadata.response_code_description`
- `Payment::getApprovalCodeAttribute()` — accessor for `metadata.approval_code`
- `SatimProcessor::formatPaymentForDisplay(Payment)` — returns a standardized display structure with all receipt/status fields so consumers don't need to dig into metadata

---

## [2.1.13] - 2026-03-24

### Fixed
- Set `redirectExpiresAt` on SATIM payment redirects instead of `null`, so `isRedirectExpired()` and `isRedirectReady()` work correctly
- Store `redirect_expires_at` in payment metadata during `SatimProcessor::doProcess()`

### Added
- Configurable `satim_session_ttl_minutes` in `config/payable.php` (default: 15 minutes, env: `SATIM_SESSION_TTL_MINUTES`)

---

## [2.1.11] - 2026-01-04

### Fixed
- Handle 'Access denied' errors in `SatimProcessor` with specific `SatimAccessDeniedException`

---

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
