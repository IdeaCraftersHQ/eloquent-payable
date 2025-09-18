<?php

namespace Ideacrafters\EloquentPayable\Contracts;

interface PaymentRedirect
{
    /**
     * Get the redirect URL for payment.
     *
     * @return string
     */
    public function getRedirectUrl(): string;

    /**
     * Get the success redirect URL.
     *
     * @return string
     */
    public function getSuccessUrl(): string;

    /**
     * Get the cancel redirect URL.
     *
     * @return string
     */
    public function getCancelUrl(): string;

    /**
     * Get the failure redirect URL.
     *
     * @return string
     */
    public function getFailureUrl(): string;

    /**
     * Get the redirect method (GET, POST, etc.).
     *
     * @return string
     */
    public function getRedirectMethod(): string;

    /**
     * Get any additional data needed for the redirect.
     *
     * @return array
     */
    public function getRedirectData(): array;

    /**
     * Check if the redirect is ready.
     *
     * @return bool
     */
    public function isRedirectReady(): bool;

    /**
     * Get the redirect session ID or token.
     *
     * @return string|null
     */
    public function getRedirectSessionId(): ?string;

    /**
     * Get the redirect expiration time.
     *
     * @return \DateTimeInterface|null
     */
    public function getRedirectExpiresAt(): ?\DateTimeInterface;

    /**
     * Check if the redirect has expired.
     *
     * @return bool
     */
    public function isRedirectExpired(): bool;

    /**
     * Get the redirect metadata.
     *
     * @return array
     */
    public function getRedirectMetadata(): array;
}
