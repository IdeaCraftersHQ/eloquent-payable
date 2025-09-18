# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
