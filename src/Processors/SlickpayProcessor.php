<?php

namespace Ideacrafters\EloquentPayable\Processors;

use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\Contracts\PaymentRedirect;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Models\PaymentRedirectModel;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Ideacrafters\EloquentPayable\PaymentStatus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

class SlickpayProcessor extends BaseProcessor
{
    /**
     * Get the processor name.
     *
     * @return string
     */
    public function getName(): string
    {
        return ProcessorNames::SLICKPAY;
    }

    /**
     * Get the default currency for Slickpay.
     * Slickpay uses DZD (Algerian Dinar) as its default currency.
     *
     * @return string
     */
    public function getCurrency(): string
    {
        return 'DZD';
    }

    /**
     * Process a payment for the given payable item and payer.
     * This creates a redirect-based payment since Slickpay requires external payment completion.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    /**
     * Process a payment with Slickpay-specific logic.
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
        // Create invoice via Slickpay API
        $invoiceResponse = $this->createInvoice($payable, $payer, $amount, $options);

        if (!$invoiceResponse['success']) {
            throw new PaymentException('Failed to create invoice: ' . ($invoiceResponse['message'] ?? 'Unknown error'));
        }

        $payment->update([
            'reference' => $invoiceResponse['id'],
            'status' => PaymentStatus::processing(),
            'metadata' => array_merge($payment->metadata ?? [], [
                'slickpay_invoice_id' => $invoiceResponse['id'],
                'slickpay_payment_url' => $invoiceResponse['url'],
            ]),
        ]);

        return $payment;
    }

    /**
     * Create a redirect-based payment using Slickpay.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return PaymentRedirect
     */
    protected function doCreateRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): array
    {
        // Use processPaymentWithoutEvents() to reuse validation and processing logic without firing events
        $payment = $this->processPaymentWithoutEvents($payable, $payer, $amount, $options);

        $paymentUrl = $payment->metadata['slickpay_payment_url'] ?? null;

        if (!$paymentUrl) {
            throw new PaymentException('Failed to get payment URL from Slickpay');
        }

        return [
            'payment' => $payment,
            'redirect' => new PaymentRedirectModel(
                redirectUrl: $paymentUrl,
                successUrl: $options['success_url'],
                cancelUrl: null,
                failureUrl: null,
                redirectMethod: 'GET',
                redirectData: [],
                redirectSessionId: $payment->reference,
                redirectExpiresAt: null, // Slickpay doesn't specify expiration
                redirectMetadata: [
                    'slickpay_invoice_id' => $payment->reference,
                    'slickpay_payment_url' => $paymentUrl,
                ]
            ),
        ];
    }

    /**
     * Complete a redirect-based payment by checking the invoice status.
     *
     * @param  Payment  $payment
     * @param  array  $redirectData
     * @return Payment
     */
    protected function doCompleteRedirect(Payment $payment, array $redirectData = []): Payment
    {
        if (!$payment->reference) {
            throw new PaymentException('Cannot complete redirect payment without Slickpay invoice ID.');
        }

        try {
            $invoiceStatus = $this->getInvoiceStatus($payment->reference);

            if ($invoiceStatus['completed']) {
                $payment->markAsPaid();
            } else {
                // Keep as processing if not completed yet
                $payment->update(['status' => PaymentStatus::processing()]);
            }

            return $payment;
        } catch (\Exception $e) {
            $payment->markAsFailed('Failed to complete redirect payment: ' . $e->getMessage());
            throw new PaymentException('Redirect payment completion failed: ' . $e->getMessage(), 0, $e);
        }
    }

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
        return false; // Not implemented in first iteration
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
            'webhooks' => false, // Not implemented in first iteration
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
            'api_key' => 'Slickpay API key',
            'sandbox_mode' => 'Enable sandbox mode (boolean)',
            'dev_api' => 'Development API URL',
            'prod_api' => 'Production API URL',
            'fallbacks.first_name' => 'Fallback first name',
            'fallbacks.last_name' => 'Fallback last name',
            'fallbacks.address' => 'Fallback address',
            'fallbacks.phone' => 'Fallback phone number',
            'fallbacks.email' => 'Fallback email address',
        ];
    }

    /**
     * Cancel a payment.
     * Not supported by Slickpay processor.
     * This method should never be called as supportsCancellation() returns false.
     *
     * @param  Payment  $payment
     * @param  string|null  $reason
     * @return Payment
     */
    protected function doCancel(Payment $payment, ?string $reason = null): Payment
    {
        throw new PaymentException('Payment cancellation not supported by Slickpay processor.');
    }

    /**
     * Refund a payment.
     * Not implemented in first iteration.
     * This method should never be called as supportsRefunds() returns false.
     *
     * @param  Payment  $payment
     * @param  float|null  $amount
     * @return Payment
     */
    protected function doRefund(Payment $payment, ?float $amount = null): Payment
    {
        throw new PaymentException('Refunds not supported by Slickpay processor in this version.');
    }

    /**
     * Create an invoice via Slickpay API.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return array
     */
    protected function createInvoice(Payable $payable, Payer $payer, float $amount, array $options = []): array
    {
        
        $payload = [
            'amount' => $amount,
            'url' => $options['success_url'],
            'firstname' => $this->getFirstName($payer),
            'lastname' => $this->getLastName($payer),
            'phone' => $this->getPhoneNumber($payer),
            'email' => $this->getEmail($payer),
            'address' => $this->getAddressString($payer),
            'items' => [
                [
                    'name' => $payable->getPayableTitle(),
                    'price' => $amount,
                    'quantity' => 1,
                ]
            ],
        ];
        try {

            $response = $this->getHttpClient()->post('/users/invoices', $payload);


            if (!$response->successful()) {
                $errorData = $response->json();
                $errorMessage = $this->formatApiErrorMessage($errorData);
                throw new PaymentException('Slickpay API request failed: ' . $errorMessage);
            }

            return $response->json();
        } catch (\Exception $e) {
            throw new PaymentException('Failed to create Slickpay invoice: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get invoice status from Slickpay API.
     *
     * @param  string  $invoiceId
     * @return array
     */
    protected function getInvoiceStatus(string $invoiceId): array
    {
        try {
            $response = $this->getHttpClient()->get('/users/invoices/' . $invoiceId);

            if (!$response->successful()) {
                throw new PaymentException('Slickpay API request failed: ' . $response->body());
            }

            $data = $response->json();

            return [
                'completed' => $data['data']['completed'] ?? false,
                'status' => $data['data']['status'] ?? 'unknown',
            ];
        } catch (\Exception $e) {
            throw new PaymentException('Failed to get Slickpay invoice status: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the base URL for Slickpay API based on sandbox mode.
     *
     * @return string
     */
    protected function getBaseUrl(): string
    {
        $sandboxMode = Config::get('payable.slickpay.sandbox_mode', true);

        if ($sandboxMode) {
            return Config::get('payable.slickpay.dev_api', 'https://devapi.slick-pay.com/api/v2');
        }

        return Config::get('payable.slickpay.prod_api', 'https://prodapi.slick-pay.com/api/v2');
    }



    /**
     * Extract first name from payer's name.
     *
     * @param  Payer  $payer
     * @return string
     */
    protected function getFirstName(Payer $payer): string
    {
        return $payer->getFirstName() ?? Config::get('payable.slickpay.fallbacks.first_name', 'Customer');
    }

    /**
     * Extract last name from payer's name.
     *
     * @param  Payer  $payer
     * @return string
     */
    protected function getLastName(Payer $payer): string
    {
        return $payer->getLastName() ?? Config::get('payable.slickpay.fallbacks.last_name', '');
    }

    /**
     * Get address string from payer's billing address.
     *
     * @param  Payer  $payer
     * @return string
     */
    protected function getAddressString(Payer $payer): string
    {
        $addressString = $payer->getBillingAddressAsString();
        
        if (!empty($addressString)) {
            return $addressString;
        }

        return Config::get('payable.slickpay.fallbacks.address', '');
    }

    /**
     * Get phone number with fallback.
     *
     * @param  Payer  $payer
     * @return string
     */
    protected function getPhoneNumber(Payer $payer): string
    {
        $phone = $payer->getPhoneNumber();

        if (!empty($phone)) {
            return $phone;
        }

        return Config::get('payable.slickpay.fallbacks.phone', '0000000000');
    }

    /**
     * Get email with fallback.
     *
     * @param  Payer  $payer
     * @return string
     */
    protected function getEmail(Payer $payer): string
    {
        $email = $payer->getEmail();

        if (!empty($email)) {
            return $email;
        }

        // Email is required, so we need a fallback
        $fallbackEmail = Config::get('payable.slickpay.fallbacks.email', 'customer@example.com');
        if (empty($fallbackEmail)) {
            throw new PaymentException('Email is required but not provided by payer and no fallback email configured.');
        }

        return $fallbackEmail;
    }

    /**
     * Get default success URL for redirects.
     *
     * @return string
     */
    protected function getDefaultSuccessUrl(): string
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');
        return url("{$prefix}/callback/success");
    }

    /**
     * Format API error message from Slickpay response.
     *
     * @param  array  $errorData
     * @return string
     */
    protected function formatApiErrorMessage(array $errorData): string
    {
        $message = $errorData['message'] ?? 'Unknown error';

        if (isset($errorData['errors']) && is_array($errorData['errors'])) {
            $errorMessages = [];
            foreach ($errorData['errors'] as $field => $fieldErrors) {
                if (is_array($fieldErrors)) {
                    $errorMessages[] = $field . ': ' . implode(', ', $fieldErrors);
                } else {
                    $errorMessages[] = $field . ': ' . $fieldErrors;
                }
            }

            if (!empty($errorMessages)) {
                $message .= ' (' . implode('; ', $errorMessages) . ')';
            }
        }

        return $message;
    }


    protected function getHttpClient(): PendingRequest
    {
        $apiKey = Config::get('payable.slickpay.api_key');
        $baseUrl = $this->getBaseUrl();
        if (!$apiKey) {
            throw new PaymentException('Slickpay API key not configured.');
        }
        return Http::asJson()
            ->baseUrl($baseUrl)
            ->withToken($apiKey);
    }
}
