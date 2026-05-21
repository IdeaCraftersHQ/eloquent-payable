<?php

namespace Ideacrafters\EloquentPayable\Credentials;

use Ideacrafters\EloquentPayable\Exceptions\InvalidCredentialBundleException;

/**
 * Value object holding a complete set of credentials for a single payment
 * processor. Use the per-processor factory methods (`forSatim`, `forSlickpay`)
 * to construct — they enforce the all-or-nothing rule on required auth fields.
 *
 * Processors call this from their `resolveCredentials()` helper after the
 * resolver closure returns a raw array. Throwing here is preferable to
 * silently mixing platform/tenant credentials downstream.
 */
final class CredentialBundle
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    private function __construct(
        public readonly string $processor,
        public readonly array $credentials,
    ) {}

    /**
     * Build a SATIM credential bundle. Required: username, password,
     * terminal_id. Optional: api_url, language, currency, verify_ssl,
     * timeout, connect_timeout (fall back to env defaults in the processor).
     *
     * @param  array<string, mixed>  $credentials
     */
    public static function forSatim(array $credentials): self
    {
        self::requireFields('satim', $credentials, ['username', 'password', 'terminal_id']);

        return new self('satim', $credentials);
    }

    /**
     * Build a Slickpay credential bundle. Required: api_key. Optional:
     * sandbox_mode (falls back to env default).
     *
     * @param  array<string, mixed>  $credentials
     */
    public static function forSlickpay(array $credentials): self
    {
        self::requireFields('slickpay', $credentials, ['api_key']);

        return new self('slickpay', $credentials);
    }

    /**
     * Fetch a credential field with an optional fallback (typically the
     * env-config default the processor should use when the resolver
     * omitted an optional field).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->credentials[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->credentials;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<int, string>    $required
     */
    private static function requireFields(string $processor, array $credentials, array $required): void
    {
        foreach ($required as $field) {
            $value = $credentials[$field] ?? null;
            if ($value === null || $value === '') {
                throw new InvalidCredentialBundleException(
                    "Credential bundle for processor `{$processor}` is missing required field `{$field}`. ".
                    'All authentication fields must be issued together — partial bundles indicate a misconfigured resolver.'
                );
            }
        }
    }
}
