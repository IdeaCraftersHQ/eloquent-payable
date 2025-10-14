<?php

namespace Ideacrafters\EloquentPayable\Models;

use Ideacrafters\EloquentPayable\Contracts\PaymentRedirect;
use Carbon\Carbon;

class PaymentRedirectModel implements PaymentRedirect
{
    /**
     * The redirect URL.
     *
     * @var string
     */
    protected $redirectUrl;

    /**
     * The success URL.
     *
     * @var string
     */
    protected $successUrl;

    /**
     * The cancel URL.
     *
     * @var string
     */
    protected $cancelUrl;

    /**
     * The failure URL.
     *
     * @var string
     */
    protected $failureUrl;

    /**
     * The redirect method.
     *
     * @var string
     */
    protected $redirectMethod;

    /**
     * The redirect data.
     *
     * @var array
     */
    protected $redirectData;

    /**
     * The redirect session ID.
     *
     * @var string|null
     */
    protected $redirectSessionId;

    /**
     * The redirect expiration time.
     *
     * @var \DateTimeInterface|null
     */
    protected $redirectExpiresAt;

    /**
     * The redirect metadata.
     *
     * @var array
     */
    protected $redirectMetadata;

    /**
     * Create a new payment redirect instance.
     *
     * @param  string  $redirectUrl
     * @param  string  $successUrl
     * @param  string  $cancelUrl
     * @param  string  $failureUrl
     * @param  string  $redirectMethod
     * @param  array  $redirectData
     * @param  string|null  $redirectSessionId
     * @param  \DateTimeInterface|null  $redirectExpiresAt
     * @param  array  $redirectMetadata
     */
    public function __construct(
        string $redirectUrl,
        string $successUrl,
        ?string $cancelUrl,
        ?string $failureUrl,
        string $redirectMethod = 'GET',
        array $redirectData = [],
        ?string $redirectSessionId = null,
        ?\DateTimeInterface $redirectExpiresAt = null,
        array $redirectMetadata = []
    ) {
        $this->redirectUrl = $redirectUrl;
        $this->successUrl = $successUrl;
        $this->cancelUrl = $cancelUrl;
        $this->failureUrl = $failureUrl;
        $this->redirectMethod = $redirectMethod;
        $this->redirectData = $redirectData;
        $this->redirectSessionId = $redirectSessionId;
        $this->redirectExpiresAt = $redirectExpiresAt;
        $this->redirectMetadata = $redirectMetadata;
    }

    /**
     * Get the redirect URL for payment.
     *
     * @return string
     */
    public function getRedirectUrl(): string
    {
        return $this->redirectUrl;
    }

    /**
     * Get the success redirect URL.
     *
     * @return string
     */
    public function getSuccessUrl(): string
    {
        return $this->successUrl;
    }

    /**
     * Get the cancel redirect URL.
     *
     * @return string
     */
    public function getCancelUrl(): string
    {
        return $this->cancelUrl;
    }

    /**
     * Get the failure redirect URL.
     *
     * @return string
     */
    public function getFailureUrl(): string
    {
        return $this->failureUrl;
    }

    /**
     * Get the redirect method (GET, POST, etc.).
     *
     * @return string
     */
    public function getRedirectMethod(): string
    {
        return $this->redirectMethod;
    }

    /**
     * Get any additional data needed for the redirect.
     *
     * @return array
     */
    public function getRedirectData(): array
    {
        return $this->redirectData;
    }

    /**
     * Check if the redirect is ready.
     *
     * @return bool
     */
    public function isRedirectReady(): bool
    {
        return !empty($this->redirectUrl) && !$this->isRedirectExpired();
    }

    /**
     * Get the redirect session ID or token.
     *
     * @return string|null
     */
    public function getRedirectSessionId(): ?string
    {
        return $this->redirectSessionId;
    }

    /**
     * Get the redirect expiration time.
     *
     * @return \DateTimeInterface|null
     */
    public function getRedirectExpiresAt(): ?\DateTimeInterface
    {
        return $this->redirectExpiresAt;
    }

    /**
     * Check if the redirect has expired.
     *
     * @return bool
     */
    public function isRedirectExpired(): bool
    {
        if (!$this->redirectExpiresAt) {
            return false;
        }

        return Carbon::now()->isAfter($this->redirectExpiresAt);
    }

    /**
     * Get the redirect metadata.
     *
     * @return array
     */
    public function getRedirectMetadata(): array
    {
        return $this->redirectMetadata;
    }

    /**
     * Convert the redirect to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'redirect_url' => $this->redirectUrl,
            'success_url' => $this->successUrl,
            'cancel_url' => $this->cancelUrl,
            'failure_url' => $this->failureUrl,
            'redirect_method' => $this->redirectMethod,
            'redirect_data' => $this->redirectData,
            'redirect_session_id' => $this->redirectSessionId,
            'redirect_expires_at' => $this->redirectExpiresAt?->format('Y-m-d H:i:s'),
            'redirect_metadata' => $this->redirectMetadata,
            'is_ready' => $this->isRedirectReady(),
            'is_expired' => $this->isRedirectExpired(),
        ];
    }

    /**
     * Convert the redirect to JSON.
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}
