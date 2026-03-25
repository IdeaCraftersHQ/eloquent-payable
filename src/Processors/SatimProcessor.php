<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Ideacrafters\EloquentPayable\Exceptions\SatimAccessDeniedException;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Models\PaymentRedirectModel;
use Ideacrafters\EloquentPayable\PaymentStatus;
use Ideacrafters\SatimLaravel\Exceptions\SatimException;
use Ideacrafters\SatimLaravel\Facades\Satim;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class SatimProcessor extends BaseProcessor
{
    /*
    |--------------------------------------------------------------------------
    | Core Payment Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Process a payment with SATIM-specific logic.
     *
     * @param  Payment  $payment
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    protected function doProcess(Payment $payment, Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        $successUrl = $options['success_url'] ?? $this->getDefaultSuccessUrl();
        $failUrl = $options['fail_url'] ?? $this->getDefaultFailUrl();

        try {
            $satimRequest = Satim::amount($this->convertToCents($amount))
                ->returnUrl($successUrl)
                ->failUrl($failUrl);

            // Add optional fields if provided
            if (isset($options['order_number'] )) {
                $satimRequest->orderNumber($options['order_number']);
            }

            if (isset($options['description'])) {
                $satimRequest->description($options['description']);
            } else {
                $satimRequest->description($payable->getPayableTitle());
            }

            // Add user defined fields if provided
            if (isset($options['udf1'])) {
                $satimRequest->udf1($options['udf1']);
            }
            if (isset($options['udf2'])) {
                $satimRequest->udf2($options['udf2']);
            }
            if (isset($options['udf3'])) {
                $satimRequest->udf3($options['udf3']);
            }

            $response = $satimRequest->register();

            // Convert response object to associative array
            $responseArray = is_array($response) ? $response : json_decode(json_encode($response), true);

            if (! isset($responseArray['formUrl']) || ! isset($responseArray['orderId'])) {
                throw new PaymentException('Invalid SATIM response: missing formUrl or orderId');
            }

            $payment->update([
                'reference' => $responseArray['orderId'],
                'status' => PaymentStatus::processing(),
                'metadata' => array_merge($payment->metadata ?? [], [
                    'satim_order_id' => $responseArray['orderId']??null,
                    'satim_form_url' => $responseArray['formUrl']??null,
                    'redirect_expires_at' => now()->addMinutes(
                        $options['session_ttl_minutes'] ?? config('payable.satim_session_ttl_minutes', 15)
                    )->toIso8601String(),
                ]),
            ]);

            return $payment;
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Access denied')) {
                throw new SatimAccessDeniedException('Satim Access Denied', 0, $e);
            }

            throw new PaymentException('Failed to create SATIM payment: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a redirect-based payment using SATIM.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return array
     */
    protected function doCreateRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): array
    {
        // Use processPaymentWithoutEvents() to reuse validation and processing logic without firing events
        $payment = $this->processPaymentWithoutEvents($payable, $payer, $amount, $options);

        $formUrl = $payment->metadata['satim_form_url'] ?? null;

        if (! $formUrl) {
            throw new PaymentException('Failed to get payment URL from SATIM');
        }

        return [
            'payment' => $payment,
            'redirect' => new PaymentRedirectModel(
                redirectUrl: $formUrl,
                successUrl: $options['success_url'] ?? $this->getDefaultSuccessUrl(),
                cancelUrl: $options['cancel_url'] ?? null,
                failureUrl: $options['fail_url'] ?? $this->getDefaultFailUrl(),
                redirectMethod: 'GET',
                redirectData: [],
                redirectSessionId: $payment->reference,
                redirectExpiresAt: now()->addMinutes(
                    $options['session_ttl_minutes'] ?? config('payable.satim_session_ttl_minutes', 15)
                ),
                redirectMetadata: [
                    'satim_order_id' => $payment->reference,
                    'satim_form_url' => $formUrl,
                ]
            ),
        ];
    }

    /**
     * Complete a redirect-based payment by checking the payment status.
     *
     * @param  Payment  $payment
     * @param  array  $redirectData
     * @return Payment
     */
    protected function doCompleteRedirect(Payment $payment, array $redirectData = []): Payment
    {
        if (! $payment->reference) {
            throw new PaymentException('Cannot complete redirect payment without SATIM orderId.');
        }

        try {
            $response = Satim::confirm($payment->reference);
            $statusCode = $response->orderStatus ?? null;
            $responseArray = is_array($response) ? $response : json_decode(json_encode($response), true);
            $params = $responseArray['params']??[];

            // Prepare metadata
            $metadata = array_merge($payment->metadata ?? [], [
                'satim_order_status' => $statusCode,
                'satim_confirmation_response' => $responseArray,
                'order_number' => $responseArray['orderNumber']??null,
                'order_status' => $responseArray['OrderStatus']??null,
                'error_code' => $responseArray['ErrorCode']??null,
                'approval_code' => $responseArray['approvalCode']??null,
                'response_code' => $params['respCode']??null,
                'response_code_description' => $params['respCode_desc']??null,
                'card_holder_name' => $responseArray['cardholderName']??null,
                
            ]);

            // Wrap status and metadata updates in a transaction for consistency
            DB::transaction(function () use ($payment, $response, $statusCode, $metadata) {
                // Update status
                if ($response->isPaid()) {
                    $payment->markAsPaid();
                } else {
                    if (in_array($statusCode, [3, 6])) {
                        // 3 = Authorization cancelled, 6 = Authorization declined
                        $payment->markAsFailed('Payment was declined or cancelled by SATIM');
                    } else {
                        // Keep as processing if not completed yet
                        $payment->update(['status' => PaymentStatus::processing()]);
                    }
                }

                // Update metadata (always updated, so do it once after status update)
                $payment->update(['metadata' => $metadata]);
            });

            return $payment;
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Access denied')) {
                throw new SatimAccessDeniedException('Satim Access Denied', 0, $e);
            }

            // Extract SATIM response context from SatimException before marking as failed
            $this->storeSatimErrorContext($payment, $e);

            $payment->markAsFailed('Failed to complete redirect payment: '.$e->getMessage());
            throw new PaymentException('Redirect payment completion failed: '.$e->getMessage(), 0, $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Processor Identity
    |--------------------------------------------------------------------------
    */

    /**
     * Get the processor name.
     *
     * @return string
     */
    public function getName(): string
    {
        return ProcessorNames::SATIM;
    }

    /**
     * Get the default currency for SATIM.
     * SATIM uses DZD (Algerian Dinar) as its currency.
     *
     * @return string
     */
    public function getCurrency(): string
    {
        return 'DZD';
    }

    /*
    |--------------------------------------------------------------------------
    | Feature Support Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if the processor supports redirect-based payments.
     *
     * @return bool
     */
    public function supportsRedirects(): bool
    {
        return true;
    }

    /**
     * Check if the processor supports immediate payments.
     *
     * @return bool
     */
    public function supportsImmediatePayments(): bool
    {
        return false;
    }

    /**
     * Check if the processor supports payment cancellation.
     *
     * @return bool
     */
    public function supportsCancellation(): bool
    {
        return false;
    }

    /**
     * Check if the processor supports refunds.
     *
     * @return bool
     */
    public function supportsRefunds(): bool
    {
        return true;
    }

    /**
     * Check if this is an offline processor.
     *
     * @return bool
     */
    public function isOffline(): bool
    {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration & Metadata
    |--------------------------------------------------------------------------
    */

    /**
     * Get the processor's supported features.
     *
     * @return array
     */
    public function getSupportedFeatures(): array
    {
        return [
            'immediate_payments' => $this->supportsImmediatePayments(),
            'redirect_payments' => $this->supportsRedirects(),
            'refunds' => $this->supportsRefunds(),
            'cancellation' => $this->supportsCancellation(),
            'webhooks' => false,
        ];
    }

    /**
     * Validate payment options for this processor.
     *
     * @param  array  $options
     * @return bool
     */
    public function validateOptions(array $options): bool
    {
        return true;
    }

    /**
     * Get the processor's configuration requirements.
     *
     * @return array
     */
    public function getConfigurationRequirements(): array
    {
        return [
            'username' => 'SATIM merchant username',
            'password' => 'SATIM merchant password',
            'terminal_id' => 'SATIM terminal identifier',
            'language' => 'Language (fr/en/ar)',
            'currency' => 'Currency code (012 for DZD)',
            'api_url' => 'SATIM API URL (test or production)',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Display & Error Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Extract SATIM error details from a payment's metadata for display purposes.
     *
     * Returns a normalized array with fallback chain:
     * satim_confirmation_response → flat metadata fields.
     *
     * @param  Payment  $payment
     * @return array|null
     */
    public function getErrorDetailsForDisplay(Payment $payment): ?array
    {
        if (! $payment->isFailed()) {
            return null;
        }

        $meta = $payment->metadata ?? [];
        $confirmResponse = $meta['satim_confirmation_response'] ?? [];
        $params = $confirmResponse['params'] ?? [];

        return [
            'action_code' => $confirmResponse['actionCode'] ?? $meta['error_code'] ?? null,
            'action_code_description' => $confirmResponse['actionCodeDescription'] ?? $meta['response_code_description'] ?? null,
            'order_number' => $confirmResponse['orderNumber'] ?? $meta['order_number'] ?? null,
            'order_status' => $confirmResponse['OrderStatus'] ?? $meta['order_status'] ?? null,
            'resp_code' => ($params['respCode'] ?? null) ?? $meta['response_code'] ?? null,
            'resp_code_desc' => ($params['respCode_desc'] ?? null) ?? $meta['response_code_description'] ?? null,
        ];
    }

    /**
     * Format a SATIM payment into a standardized display structure.
     *
     * Returns all relevant fields for receipt/status rendering so that
     * consumers (Vue pages, Blade templates, PDFs) don't need to dig
     * into metadata or know about SATIM response internals.
     *
     * @param  Payment  $payment
     * @return array
     */
    public function formatPaymentForDisplay(Payment $payment): array
    {
        $meta = $payment->metadata ?? [];
        $confirmResponse = $meta['satim_confirmation_response'] ?? [];

        $isFailed = $payment->isFailed();
        $isCompleted = $payment->isCompleted();

        return [
            'reference' => $payment->reference,
            'order_number' => $confirmResponse['orderNumber'] ?? $meta['order_number'] ?? null,
            'approval_code' => $meta['approval_code'] ?? $confirmResponse['approvalCode'] ?? null,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'status' => (string) $payment->status,
            'method' => 'carte CIB/Edhahabia',
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
            'is_completed' => $isCompleted,
            'is_failed' => $isFailed,
            'error_details' => $isFailed ? $this->getErrorDetailsForDisplay($payment) : null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Protected Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Extract SATIM response data from exception and store it in payment metadata.
     *
     * When satim-laravel throws SatimException for declined payments (e.g. ErrorCode 2),
     * the confirmation response metadata (respCode, actionCode, etc.) is only available
     * in the exception's context. This method recovers that data before the payment is
     * marked as failed.
     *
     * @param  Payment  $payment
     * @param  \Exception  $e
     * @return void
     */
    protected function storeSatimErrorContext(Payment $payment, \Exception $e): void
    {
        $satimException = $e instanceof SatimException
            ? $e
            : ($e->getPrevious() instanceof SatimException ? $e->getPrevious() : null);

        if (! $satimException || ! $satimException->getContext()) {
            return;
        }

        $context = $satimException->getContext();
        $params = $context['params'] ?? [];

        $payment->update([
            'metadata' => array_merge($payment->metadata ?? [], [
                'satim_confirmation_response' => $context,
                'order_number' => $context['orderNumber'] ?? $payment->metadata['order_number'] ?? null,
                'order_status' => $context['OrderStatus'] ?? null,
                'error_code' => $context['ErrorCode'] ?? null,
                'response_code' => $params['respCode'] ?? null,
                'response_code_description' => $params['respCode_desc'] ?? null,
            ]),
        ]);
    }

    /**
     * Cancel a payment.
     * Not supported by SATIM processor.
     *
     * @param  Payment  $payment
     * @param  string|null  $reason
     * @return Payment
     */
    protected function doCancel(Payment $payment, ?string $reason = null): Payment
    {
        throw new PaymentException('Payment cancellation not supported by SATIM processor.');
    }

    /**
     * Refund a payment.
     *
     * @param  Payment  $payment
     * @param  float|null  $amount
     * @return Payment
     */
    protected function doRefund(Payment $payment, ?float $amount = null): Payment
    {
        if (! $payment->reference) {
            throw new PaymentException('Cannot refund payment without SATIM order reference.');
        }

        $refundAmount = $amount ?? $payment->amount;

        try {
            $response = Satim::refund($payment->reference, $this->convertToCents($refundAmount));
            
            // Convert response object to associative array
            $responseArray = is_array($response) ? $response : json_decode(json_encode($response), true);

            if (! ($responseArray['success'] ?? false)) {
                throw new PaymentException('SATIM refund failed: '.($responseArray['message'] ?? 'Unknown error'));
            }

            $totalRefunded = ($payment->refunded_amount ?? 0) + $refundAmount;

            if ($totalRefunded >= $payment->amount) {
                $payment->update([
                    'status' => PaymentStatus::refunded(),
                    'refunded_amount' => $totalRefunded,
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'satim_refund_response' => $responseArray,
                    ]),
                ]);
            } else {
                $payment->update([
                    'status' => PaymentStatus::partiallyRefunded(),
                    'refunded_amount' => $totalRefunded,
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'satim_refund_response' => $responseArray,
                    ]),
                ]);
            }

            return $payment;
        } catch (\Exception $e) {
            throw new PaymentException('Failed to refund SATIM payment: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Convert amount to cents for SATIM.
     * SATIM requires amounts in centimes (smallest currency unit).
     * For DZD: 1 DZD = 100 centimes.
     *
     * @param  float  $amount
     * @return int
     */
    protected function convertToCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Get default success URL for redirects.
     *
     * @return string
     */
    protected function getDefaultSuccessUrl(): string
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');

        return url("{$prefix}/redirect/success");
    }

    /**
     * Get default failure URL for redirects.
     *
     * @return string
     */
    protected function getDefaultFailUrl(): string
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');

        return url("{$prefix}/redirect/failed");
    }
}
