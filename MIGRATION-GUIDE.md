# Migration Guides

This is the main migration guide index. Select the appropriate guide for your upgrade path.

## Available Migration Guides

- **[From 1.x.x to 2.0.0](MIGRATION-GUIDE-1.x-to-2.0.md)** (Latest) ⭐

---

## Quick Start

If you're upgrading from version 1.x, start with the **[1.x to 2.0 migration guide](MIGRATION-GUIDE-1.x-to-2.0.md)**.

For a complete list of changes, see [CHANGELOG.md](CHANGELOG.md).

---

## What's New in 2.0.0?

Version 2.0.0 introduces significant improvements:

- **Payment Canceled State** - New `canceled` status for payments
- **Currency Validation** - Automatic currency validation per processor
- **Enhanced Event System** - Configurable events with automatic event firing
- **PaymentStatus Class** - Centralized status access
- **Automatic Lifecycle Management** - Automatic timestamp management
- **Payment Model Refactoring** - Improved code organization with traits
- **Processor Architecture Refactoring** - Centralized validation and event emission

> ⚠️ **Breaking Changes:** Version 2.0.0 includes breaking changes. If you have custom processors, you **must** refactor them. See the [migration guide](MIGRATION-GUIDE-1.x-to-2.0.md) for details.

---

## Need Help?

- Check the [README.md](README.md) for general documentation
- Review the [CHANGELOG.md](CHANGELOG.md) for detailed changes
- Open an issue on GitHub if you encounter problems during migration

