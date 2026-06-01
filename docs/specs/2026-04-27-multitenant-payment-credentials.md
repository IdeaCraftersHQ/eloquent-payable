# Multitenant Payment Credentials

**Status:** Proposed
**Date:** 2026-04-27
**Scope:** `eloquent-payable` package + Tutorios adoption
**Processors in scope:** SATIM, Slickpay
**Processors out of scope (this round):** Stripe

---

## Summary

Make `eloquent-payable` resolve payment-processor credentials **per-payment** instead of per-application, so that each teacher in Tutorios can receive payments into their own SATIM / Slickpay merchant account. When a teacher has not configured their own credentials, fall back to the application's `.env` credentials transparently.

The package gains a small, generic resolver hook; the host application (Tutorios) owns credential storage and the domain mapping from a payment to a merchant.

---

## Problem

Today `SatimProcessor` and `SlickpayProcessor` resolve credentials at boot from `config('payable.satim.*')` / `config('payable.slickpay.*')`, which read from `.env`. The processors are bound to a single global credential set for the lifetime of the process. There is no way to vary credentials per call.

Tutorios needs the opposite: every teacher's payments should settle into *that teacher's* merchant account, not into the platform's. Today every payment ends up in the same wallet regardless of which teacher's Room the student subscribed to.

The redirect-based flow makes this harder than a simple "thread the credentials through the call." A payment lifecycle spans multiple HTTP requests and may extend over days:

1. **Create:** student clicks Pay → `doCreateRedirect()` registers the order with SATIM, gets `orderId`, persists `Payment`, returns redirect URL.
2. **Confirm:** student returns from SATIM hours later → `doCompleteRedirect($payment)` calls SATIM `confirm` to verify status. **Different request, no caller context.**
3. **Refund:** days or weeks later → `doRefund($payment)` calls SATIM `refund`. **Different session, no caller context.**

Steps 2 and 3 must use the **same** merchant's credentials as step 1. Whatever mechanism we introduce has to survive across these boundaries.

---

## Goals

- Each `Payment` record can be processed against a different set of SATIM / Slickpay credentials, determined by the host application.
- The `eloquent-payable` package remains domain-agnostic — it does not know what a "Teacher" is.
- When the host has no per-tenant credentials for a given payment, env-based credentials are used. Existing single-tenant deployments require no migration and no behavior change.
- Per-tenant credentials are never stored in the `payments` table. Only an opaque tenant pointer is persisted, sufficient for the host to re-resolve credentials on later requests.
- The resolver mechanism is symmetric across all credential-bearing processors and easy to extend.

## Non-goals

- Stripe multitenancy. Stripe webhook verification is per-account, which makes raw per-tenant API keys structurally awkward (the handler needs the secret to verify, but cannot identify the tenant before verifying). Stripe should be tackled as a separate workstream using **Stripe Connect**, not raw per-tenant keys.
- Marketplace fees, payouts, or revenue-share logic. Out of scope.
- Per-processor concurrency limits, caching, or pooling. Each call instantiates its own client; if optimization is needed later, it can be added inside the factory without changing the resolver contract.

---

## Architecture

### Resolver mechanism (package side)

`PayableManager` exposes a per-processor registration API:

```php
PayableManager::resolveCredentialsFor('satim', function (Payment $payment): ?array {
    // return a complete credentials array, or null to use env defaults
});

PayableManager::resolveCredentialsFor('slickpay', function (Payment $payment): ?array {
    // ...
});
```

The callback signature: `fn(Payment $payment): ?array`.

- Returns a complete credentials array → the processor uses those credentials for this call.
- Returns `null` → the processor falls back to `config('payable.<processor>.*')` (the env values).
- Throws → propagates as a `PaymentException`. The host is responsible for not throwing on "merchant not configured" — that case must return `null`.

If no resolver is registered for a processor, the processor uses env credentials. **Existing single-tenant behavior is unchanged.**

### Credential shapes

Each processor publishes the keys it requires.

**SATIM**
```php
[
    'username'    => string,   // required
    'password'    => string,   // required
    'terminal_id' => string,   // required
    'api_url'     => ?string,  // optional, defaults to config('payable.satim.api_url')
    'language'    => ?string,  // optional, defaults to config('payable.satim.language')
    'currency'    => ?string,  // optional, defaults to config('payable.satim.currency')
]
```

**Slickpay**
```php
[
    'api_key'      => string,  // required
    'sandbox_mode' => ?bool,   // optional, defaults to config('payable.slickpay.sandbox_mode')
]
```

**All-or-nothing on auth fields.** A resolver that returns a partial bundle (e.g. SATIM `username` without `password`) is treated as a configuration error and throws `PaymentException`. Auth fields are issued together as a unit by the payment provider; mixing one tenant's `username` with another tenant's `password` (or with the platform's password) silently produces auth errors that are painful to debug.

### Per-call client construction

Inside `SatimProcessor`:

```php
$creds = $this->resolveCredentials($payment) ?? $this->envCredentials();
$client = new SatimClient(
    apiUrl: $creds['api_url'],
    username: $creds['username'],
    password: $creds['password'],
    verifySSL: $creds['verify_ssl'] ?? true,
    timeout: $creds['timeout'] ?? 30,
    connectTimeout: $creds['connect_timeout'] ?? 10,
);
$satim = new Satim($client, $creds['language'], $creds['currency'], $creds['terminal_id']);
$response = $satim->amount(...)->returnUrl(...)->register();
```

The underlying `ideacrafters/satim-laravel` `SatimClient` is a plain class with a public constructor taking all credentials as arguments. There is no singleton enforcement at the class level — the global behavior comes only from the service provider's container binding. We bypass the binding for credential-resolved calls and instantiate directly.

The `Satim` facade is **not used** in any code path that touches per-tenant credentials. Existing tests/code that use the facade for env-based flows continue to work; we only change the in-processor call sites.

### Replay safety: merchant pointer in payment metadata

At create time (`doProcess` / `doCreateRedirect`), the processor calls the resolver with the freshly-created `Payment`. Tutorios's resolver walks `$payment->payable->room->teacher` and returns credentials.

To make confirmation and refund robust against future domain changes (teacher deleted, room transferred), the processor also stores a **merchant pointer** in `payment.metadata`:

```php
$payment->update([
    'metadata' => array_merge($payment->metadata ?? [], [
        'merchant_pointer' => $options['merchant_pointer'] ?? null,
        // ...
    ]),
]);
```

The pointer is opaque to the package — it's whatever the host wants. Tutorios will pass `['type' => 'App\\Models\\User', 'id' => $teacher->id]` via `$options`. **No credentials are persisted.** Only the pointer.

At confirmation/refund time, the resolver receives the Payment and can prefer `$payment->metadata['merchant_pointer']` over walking relations, which guarantees the same merchant is used even if the chain `payable → room → teacher` has shifted.

---

## Detailed design — package

### New types

- `Ideacrafters\EloquentPayable\Credentials\CredentialResolver` — invocable wrapper around a `Closure`, normalizing return values.
- `Ideacrafters\EloquentPayable\Credentials\CredentialBundle` — value object validating shape per processor (factory methods `forSatim(array)`, `forSlickpay(array)`).
- `Ideacrafters\EloquentPayable\Exceptions\InvalidCredentialBundleException extends PaymentException` — thrown on partial / malformed resolver output.

### Modified types

- `Ideacrafters\EloquentPayable\PayableManager` — gains `resolveCredentialsFor(string $processor, Closure $callback): void` and `getCredentialResolver(string $processor): ?CredentialResolver`.
- `Ideacrafters\EloquentPayable\Processors\SatimProcessor` — drops calls to the `Satim` facade; new private helpers `resolveCredentials(Payment $payment): array` and `buildSatim(array $creds): Satim`.
- `Ideacrafters\EloquentPayable\Processors\SlickpayProcessor` — same pattern.

### Backward compatibility

- No resolver registered → env credentials, identical behavior to today.
- `payment.metadata.merchant_pointer` is optional. Existing payments without it work fine: confirm/refund just call the resolver with the raw `Payment`, and the resolver may return null → env fallback.
- The `Satim` facade is not removed; we just stop calling it in-processor.

### Tests

- Unit: `CredentialBundle::forSatim` accepts complete bundle, rejects partial.
- Unit: `PayableManager::resolveCredentialsFor` registration round-trips.
- Integration: `SatimProcessor` uses resolved credentials when resolver registered; uses env when resolver returns null; uses env when no resolver registered.
- Integration: `merchant_pointer` is persisted into metadata when present in `$options`.
- Integration: `doCompleteRedirect` re-invokes the resolver with the stored Payment and uses the same credentials as `doCreateRedirect` did.

---

## Detailed design — Tutorios adoption

This section is implemented as a separate PR after the package PR ships.

### Credential storage

A new table, `teacher_payment_credentials`:

| column          | type                | notes                                           |
| --------------- | ------------------- | ----------------------------------------------- |
| `id`            | bigint pk           |                                                 |
| `user_id`       | bigint fk users.id  | unique on (user_id, processor)                  |
| `processor`     | string              | `satim` or `slickpay`                           |
| `credentials`   | text (encrypted)    | JSON, `encrypted` cast on the model             |
| `is_active`     | boolean             | toggle without deleting                         |
| `created_at`    | timestamp           |                                                 |
| `updated_at`    | timestamp           |                                                 |

**Why a sidecar table over columns on `users`:**

- Multiple processors per teacher (SATIM + Slickpay) without bloating `users`.
- Encrypted JSON column scales naturally to new processors without further migrations.
- Easier to scope queries / authorize / audit — credentials access can be gated separately from user reads.

**Encryption:** Laravel's `encrypted` cast on the `credentials` JSON attribute. Uses `APP_KEY`. **`APP_KEY` rotation impacts these rows** — document a rotation procedure (re-encrypt rows under new key).

### Service provider binding

`App\Providers\PaymentCredentialsServiceProvider`:

```php
public function boot(): void
{
    PayableManager::resolveCredentialsFor('satim', function (Payment $payment): ?array {
        $teacher = $this->resolveTeacher($payment);
        return $teacher?->paymentCredentialsFor('satim');
    });

    PayableManager::resolveCredentialsFor('slickpay', function (Payment $payment): ?array {
        $teacher = $this->resolveTeacher($payment);
        return $teacher?->paymentCredentialsFor('slickpay');
    });
}

private function resolveTeacher(Payment $payment): ?User
{
    // Prefer the snapshotted pointer over walking relations
    if ($pointer = $payment->metadata['merchant_pointer'] ?? null) {
        return ($pointer['type'])::find($pointer['id']);
    }

    return $payment->payable?->room?->teacher;
}
```

The Subscription / Plan / Room → Teacher walk happens in one place. Anything that creates a payment must include the `merchant_pointer` in `$options` so confirmation/refund don't depend on relation state.

### Settings UI

A new page under teacher settings: **Payment accounts** (or similar).

- Tabs / cards per processor (SATIM, Slickpay).
- Form per processor — fields scoped to that processor's credential shape.
- Server-side validation on save:
  - Required fields present.
  - For SATIM: optional "Test connection" action that hits SATIM's API with a minimal request to confirm credentials are valid before persisting.
- Encrypted at rest via the model cast; never echoed back in plaintext (mask `password` / `api_key` in the UI).

### Student checkout UX

When a student attempts to pay for a Subscription whose Room's teacher has not configured the relevant processor:

- If the resolver returns null → today's behavior is "use env creds." For Tutorios we want the **opposite** — students should not silently pay into the platform account.
- Add a guard upstream of `payRedirect`: check whether the teacher has active credentials for the chosen processor; if not, surface a friendly error ("This teacher has not finished setting up their payment account. Please try again later.") and notify the teacher.

This guard lives in Tutorios, not in the package. The package's env fallback is the right default for *single-tenant* deployments that just happen to use the resolver hook for tenant-aware routing; Tutorios's UX rule (no silent fallback to platform creds) is layered on top.

---

## Security considerations

- **No credentials in `payments` table.** Only the merchant pointer.
- **Encrypted at rest** in `teacher_payment_credentials.credentials` via Laravel's `encrypted` cast.
- **Never logged.** The package's logging on payment errors must scrub credential fields if any leak into exception context. Add a regression test asserting that `username`/`password`/`api_key` never appear in log output.
- **`APP_KEY` rotation procedure** documented in Tutorios ops runbook.
- **Authorization:** only the owning teacher (and admin) can read/write their `teacher_payment_credentials` row. Filament/superadmin access policies must reflect this.
- **Audit log** on credential change events (create/update/disable). Out of scope for v1 but worth tracking.

---

## Failure modes

| scenario | behavior |
| -------- | -------- |
| Resolver throws | Propagates as `PaymentException`; payment marked failed; transaction rolled back. |
| Resolver returns partial bundle | `InvalidCredentialBundleException`; treated like resolver throwing. |
| Resolver returns null | Fall back to env credentials. (Tutorios layers a UX guard on top to forbid this.) |
| Teacher deletes credentials between create and confirm | Resolver returns null at confirm time → env fallback would charge to platform. **Mitigation:** confirm calls use the merchant pointer; if the teacher is gone, the resolver should return null *and* the host should mark the payment failed rather than silently confirming under env creds. Tutorios implements this rule. |
| Payment created with no merchant pointer | Resolver walks the relation chain; works as long as the chain is intact. |
| `payable` relation is gone (orphaned payment) | Resolver returns null; payment cannot be confirmed automatically; manual reconciliation required. |

---

## Alternatives considered

**Resolver returns a pre-built client instance.** Closer to what some adapter libraries do. Rejected: leaks SDK types into the host, harder to test, harder to evolve. Returning a plain credentials array keeps the boundary clean and serializable.

**Credentials passed via `$options` on each call.** Simplest possible change. Rejected: doesn't survive the redirect → confirm flow, where `$options` is gone by the time confirmation runs. Confirmation would need a separate mechanism anyway, so we'd end up with two.

**Mutating `Config::set('payable.satim.*', ...)` at runtime per request.** Rejected: fragile under queues and concurrency. The next job picked off the queue inherits whichever creds were last set on the worker, and there's no clean reset point.

**A single tenant-context object set on the container.** Rejected: same problem as `$options` — doesn't survive cross-request flows, forces every caller to remember to set it before invoking `pay()`/`payRedirect()`.

**Persist credentials directly on the Payment record at create time.** Rejected: huge security blast radius. We persist only a pointer.

**Including Stripe in this round.** Deferred. Stripe webhooks make raw per-tenant keys structurally awkward — the webhook handler needs the secret to verify, but cannot identify the tenant before verifying. The right pattern for Stripe is **Stripe Connect** (one platform secret, connected-account IDs per teacher), which is a different architecture and worth its own design pass when the product needs it.

---

## Implementation phasing

**PR 1 — Package** (`eloquent-payable`):
1. Add `PayableManager::resolveCredentialsFor` and `CredentialBundle`.
2. Refactor `SatimProcessor` to per-call client instantiation.
3. Refactor `SlickpayProcessor` to per-call client instantiation.
4. Add merchant-pointer persistence in payment metadata.
5. Tests: unit + integration.
6. README / CHANGELOG updates.

**PR 2 — Tutorios adoption** (separate repo):
1. Migration: `teacher_payment_credentials` table.
2. Model: `TeacherPaymentCredential` with encrypted cast; `User::paymentCredentialsFor(string $processor)` accessor.
3. `PaymentCredentialsServiceProvider` registering both resolvers.
4. Settings UI for teachers.
5. Checkout guard preventing silent env fallback.
6. Tests.

PR 1 ships first. PR 2 can be developed in parallel once PR 1's resolver contract is locked.

---

## Open questions

1. Should the package log a warning when the resolver returns null and falls back to env credentials? Useful for catching misconfigurations in development; potentially noisy in production. Default: log at `debug` level, opt-in via config.
2. Should `teacher_payment_credentials` support multiple credential rows per (teacher, processor) — e.g. one for sandbox, one for production? For v1, no. One row per pair, with `is_active` toggle.
3. Refund-policy questions — if a refund is initiated months after payment and the teacher has rotated their credentials, do we use current creds or stored historical ones? **Decision:** current. Refunds are issued from the merchant's current account; that's how SATIM/Slickpay handle it on their side.

---
