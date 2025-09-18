<?php

namespace Ideacrafters\EloquentPayable;

use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Contracts\PaymentProcessor;
use Ideacrafters\EloquentPayable\Contracts\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payer;
use Ideacrafters\EloquentPayable\Contracts\PaymentRedirect;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class PayableManager
{
    /**
     * The registered processors.
     *
     * @var array
     */
    protected $processors = [];

    /**
     * The default processor.
     *
     * @var string
     */
    protected $defaultProcessor;

    /**
     * Create a new PayableManager instance.
     */
    public function __construct()
    {
        $this->loadProcessors();
        $this->defaultProcessor = Config::get('payable.default_processor', 'stripe');
    }

    /**
     * Process a payment for the given payable item and payer.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    public function process(Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        $processor = $this->getProcessor($options['processor'] ?? null);
        
        return $processor->process($payable, $payer, $amount, $options);
    }

    /**
     * Process an offline payment for the given payable item and payer.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return Payment
     */
    public function processOffline(Payable $payable, Payer $payer, float $amount, array $options = []): Payment
    {
        $options['processor'] = 'offline';
        
        return $this->process($payable, $payer, $amount, $options);
    }

    /**
     * Refund a payment.
     *
     * @param  Payment  $payment
     * @param  float|null  $amount
     * @return Payment
     */
    public function refund(Payment $payment, ?float $amount = null): Payment
    {
        $processor = $payment->getProcessor();
        
        return $processor->refund($payment, $amount);
    }

    /**
     * Get a payment processor instance.
     *
     * @param  string|null  $name
     * @return PaymentProcessor
     */
    public function getProcessor(?string $name = null): PaymentProcessor
    {
        $name = $name ?? $this->defaultProcessor;
        
        if (!isset($this->processors[$name])) {
            throw new PaymentException("Payment processor '{$name}' not found.");
        }

        return app($this->processors[$name]);
    }

    /**
     * Get all registered processors.
     *
     * @return array
     */
    public function getProcessors(): array
    {
        return $this->processors;
    }

    /**
     * Get the default processor name.
     *
     * @return string
     */
    public function getDefaultProcessor(): string
    {
        return $this->defaultProcessor;
    }

    /**
     * Get payment URLs for callbacks and webhooks.
     *
     * @param  Payment  $payment
     * @return array
     */
    public function getPaymentUrls(Payment $payment): array
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');
        
        return [
            'success' => url("{$prefix}/callback/success?payment={$payment->id}"),
            'cancel' => url("{$prefix}/callback/cancel?payment={$payment->id}"),
            'failed' => url("{$prefix}/callback/failed?payment={$payment->id}"),
        ];
    }

    /**
     * Find a payment by ID.
     *
     * @param  int  $id
     * @return Payment|null
     */
    public function find(int $id): ?Payment
    {
        return Payment::find($id);
    }

    /**
     * Create a redirect-based payment.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @param  float  $amount
     * @param  array  $options
     * @return PaymentRedirect
     */
    public function createRedirect(Payable $payable, Payer $payer, float $amount, array $options = []): PaymentRedirect
    {
        $processor = $this->getProcessor($options['processor'] ?? null);
        
        if (!$processor->supportsRedirects()) {
            throw new PaymentException('Processor does not support redirect payments.');
        }
        
        return $processor->createRedirect($payable, $payer, $amount, $options);
    }

    /**
     * Complete a redirect-based payment.
     *
     * @param  Payment  $payment
     * @param  array  $redirectData
     * @return Payment
     */
    public function completeRedirect(Payment $payment, array $redirectData = []): Payment
    {
        $processor = $payment->getProcessor();
        
        if (!$processor->supportsRedirects()) {
            throw new PaymentException('Processor does not support redirect payments.');
        }
        
        return $processor->completeRedirect($payment, $redirectData);
    }

    /**
     * Get all payments for a payable item.
     *
     * @param  Payable  $payable
     * @return Collection
     */
    public function getPaymentsFor(Payable $payable): Collection
    {
        return Payment::where('payable_type', get_class($payable))
                     ->where('payable_id', $payable->getKey())
                     ->get();
    }

    /**
     * Get all payments by a payer.
     *
     * @param  Payer  $payer
     * @return Collection
     */
    public function getPaymentsBy(Payer $payer): Collection
    {
        return Payment::where('payer_type', get_class($payer))
                     ->where('payer_id', $payer->getKey())
                     ->get();
    }

    /**
     * Get total amount paid by a payer.
     *
     * @param  Payer  $payer
     * @return float
     */
    public function getTotalPaidBy(Payer $payer): float
    {
        return Payment::where('payer_type', get_class($payer))
                     ->where('payer_id', $payer->getKey())
                     ->completed()
                     ->sum('amount');
    }

    /**
     * Get total amount paid for a payable item.
     *
     * @param  Payable  $payable
     * @return float
     */
    public function getTotalPaidFor(Payable $payable): float
    {
        return Payment::where('payable_type', get_class($payable))
                     ->where('payable_id', $payable->getKey())
                     ->completed()
                     ->sum('amount');
    }

    /**
     * Check if a payer has paid for a payable item.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @return bool
     */
    public function hasPaidFor(Payable $payable, Payer $payer): bool
    {
        return Payment::where('payable_type', get_class($payable))
                     ->where('payable_id', $payable->getKey())
                     ->where('payer_type', get_class($payer))
                     ->where('payer_id', $payer->getKey())
                     ->completed()
                     ->exists();
    }

    /**
     * Get the latest payment for a payable item by a payer.
     *
     * @param  Payable  $payable
     * @param  Payer  $payer
     * @return Payment|null
     */
    public function getLatestPaymentFor(Payable $payable, Payer $payer): ?Payment
    {
        return Payment::where('payable_type', get_class($payable))
                     ->where('payable_id', $payable->getKey())
                     ->where('payer_type', get_class($payer))
                     ->where('payer_id', $payer->getKey())
                     ->latest()
                     ->first();
    }

    /**
     * Get all completed payments.
     *
     * @return Collection
     */
    public function getCompletedPayments(): Collection
    {
        return Payment::completed()->get();
    }

    /**
     * Get all pending payments.
     *
     * @return Collection
     */
    public function getPendingPayments(): Collection
    {
        return Payment::pending()->get();
    }

    /**
     * Get all failed payments.
     *
     * @return Collection
     */
    public function getFailedPayments(): Collection
    {
        return Payment::failed()->get();
    }

    /**
     * Get all offline payments.
     *
     * @return Collection
     */
    public function getOfflinePayments(): Collection
    {
        return Payment::offline()->get();
    }

    /**
     * Get payments from today.
     *
     * @return Collection
     */
    public function getPaymentsToday(): Collection
    {
        return Payment::today()->get();
    }

    /**
     * Get payments from this month.
     *
     * @return Collection
     */
    public function getPaymentsThisMonth(): Collection
    {
        return Payment::thisMonth()->get();
    }

    /**
     * Get payment statistics.
     *
     * @return array
     */
    public function getPaymentStats(): array
    {
        $cacheKey = 'payable.stats.payments';
        
        return Cache::remember($cacheKey, 300, function () {
            return [
                'total' => Payment::count(),
                'completed' => Payment::completed()->count(),
                'pending' => Payment::pending()->count(),
                'failed' => Payment::failed()->count(),
                'offline' => Payment::offline()->count(),
                'total_amount' => Payment::completed()->sum('amount'),
                'refunded_amount' => Payment::whereNotNull('refunded_amount')->sum('refunded_amount'),
                'today' => Payment::today()->count(),
                'this_month' => Payment::thisMonth()->count(),
            ];
        });
    }

    /**
     * Get processor statistics.
     *
     * @return array
     */
    public function getProcessorStats(): array
    {
        $cacheKey = 'payable.stats.processors';
        
        return Cache::remember($cacheKey, 300, function () {
            $stats = [];
            
            foreach ($this->processors as $name => $class) {
                $stats[$name] = [
                    'total' => Payment::where('processor', $name)->count(),
                    'completed' => Payment::where('processor', $name)->completed()->count(),
                    'pending' => Payment::where('processor', $name)->pending()->count(),
                    'failed' => Payment::where('processor', $name)->failed()->count(),
                    'total_amount' => Payment::where('processor', $name)->completed()->sum('amount'),
                ];
            }
            
            return $stats;
        });
    }

    /**
     * Mark a payment as paid.
     *
     * @param  Payment  $payment
     * @param  mixed  $paidAt
     * @return void
     */
    public function markAsPaid(Payment $payment, $paidAt = null): void
    {
        $payment->markAsPaid($paidAt);
    }

    /**
     * Mark a payment as failed.
     *
     * @param  Payment  $payment
     * @param  string|null  $reason
     * @return void
     */
    public function markAsFailed(Payment $payment, ?string $reason = null): void
    {
        $payment->markAsFailed($reason);
    }

    /**
     * Mark a payment as pending.
     *
     * @param  Payment  $payment
     * @return void
     */
    public function markAsPending(Payment $payment): void
    {
        $payment->markAsPending();
    }

    /**
     * Check if a payment is completed.
     *
     * @param  Payment  $payment
     * @return bool
     */
    public function isCompleted(Payment $payment): bool
    {
        return $payment->isCompleted();
    }

    /**
     * Check if a payment is pending.
     *
     * @param  Payment  $payment
     * @return bool
     */
    public function isPending(Payment $payment): bool
    {
        return $payment->isPending();
    }

    /**
     * Check if a payment is failed.
     *
     * @param  Payment  $payment
     * @return bool
     */
    public function isFailed(Payment $payment): bool
    {
        return $payment->isFailed();
    }

    /**
     * Check if a payment is offline.
     *
     * @param  Payment  $payment
     * @return bool
     */
    public function isOffline(Payment $payment): bool
    {
        return $payment->isOffline();
    }

    /**
     * Get the refundable amount for a payment.
     *
     * @param  Payment  $payment
     * @return float
     */
    public function getRefundableAmount(Payment $payment): float
    {
        return $payment->getRefundableAmount();
    }

    /**
     * Get webhook URLs.
     *
     * @return array
     */
    public function getWebhookUrls(): array
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');
        
        return [
            'stripe' => url("{$prefix}/webhooks/stripe"),
            'generic' => url("{$prefix}/webhooks/{processor}"),
        ];
    }

    /**
     * Get callback URLs.
     *
     * @return array
     */
    public function getCallbackUrls(): array
    {
        $prefix = Config::get('payable.routes.prefix', 'payable');
        
        return [
            'success' => url("{$prefix}/callback/success"),
            'cancel' => url("{$prefix}/callback/cancel"),
            'failed' => url("{$prefix}/callback/failed"),
        ];
    }

    /**
     * Handle a webhook.
     *
     * @param  string  $processor
     * @param  array  $payload
     * @return mixed
     */
    public function handleWebhook(string $processor, array $payload)
    {
        $processorInstance = $this->getProcessor($processor);
        
        return $processorInstance->handleWebhook($payload);
    }

    /**
     * Get supported currencies.
     *
     * @return array
     */
    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK'];
    }

    /**
     * Get the default currency.
     *
     * @return string
     */
    public function getDefaultCurrency(): string
    {
        return Config::get('payable.currency', 'USD');
    }

    /**
     * Get decimal precision.
     *
     * @return int
     */
    public function getDecimalPrecision(): int
    {
        return Config::get('payable.decimal_precision', 2);
    }

    /**
     * Get payment statuses.
     *
     * @return array
     */
    public function getPaymentStatuses(): array
    {
        return Config::get('payable.statuses', []);
    }

    /**
     * Get processor names.
     *
     * @return array
     */
    public function getProcessorNames(): array
    {
        return array_keys($this->processors);
    }

    /**
     * Check if a processor is supported.
     *
     * @param  string  $name
     * @return bool
     */
    public function isProcessorSupported(string $name): bool
    {
        return isset($this->processors[$name]);
    }

    /**
     * Set the default processor.
     *
     * @param  string  $name
     * @return void
     */
    public function setDefaultProcessor(string $name): void
    {
        if (!$this->isProcessorSupported($name)) {
            throw new PaymentException("Processor '{$name}' is not supported.");
        }
        
        $this->defaultProcessor = $name;
    }

    /**
     * Register a new processor.
     *
     * @param  string  $name
     * @param  string  $class
     * @return void
     */
    public function registerProcessor(string $name, string $class): void
    {
        $this->processors[$name] = $class;
    }

    /**
     * Unregister a processor.
     *
     * @param  string  $name
     * @return void
     */
    public function unregisterProcessor(string $name): void
    {
        unset($this->processors[$name]);
    }

    /**
     * Get configuration.
     *
     * @return array
     */
    public function getConfiguration(): array
    {
        return Config::get('payable', []);
    }

    /**
     * Set configuration.
     *
     * @param  array  $config
     * @return void
     */
    public function setConfiguration(array $config): void
    {
        Config::set('payable', $config);
    }

    /**
     * Reset configuration to defaults.
     *
     * @return void
     */
    public function resetConfiguration(): void
    {
        Config::set('payable', require __DIR__.'/../config/payable.php');
    }

    /**
     * Clear cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        Cache::forget('payable.stats.payments');
        Cache::forget('payable.stats.processors');
    }

    /**
     * Warm cache.
     *
     * @return void
     */
    public function warmCache(): void
    {
        $this->getPaymentStats();
        $this->getProcessorStats();
    }

    /**
     * Get metrics.
     *
     * @return array
     */
    public function getMetrics(): array
    {
        return [
            'payments' => $this->getPaymentStats(),
            'processors' => $this->getProcessorStats(),
            'configuration' => $this->getConfiguration(),
        ];
    }

    /**
     * Get health check status.
     *
     * @return array
     */
    public function getHealthCheck(): array
    {
        $status = 'healthy';
        $issues = [];

        // Check if processors are available
        foreach ($this->processors as $name => $class) {
            if (!class_exists($class)) {
                $status = 'unhealthy';
                $issues[] = "Processor class '{$class}' not found";
            }
        }

        // Check database connection
        try {
            Payment::count();
        } catch (\Exception $e) {
            $status = 'unhealthy';
            $issues[] = 'Database connection failed: ' . $e->getMessage();
        }

        return [
            'status' => $status,
            'issues' => $issues,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Log a message.
     *
     * @param  string  $level
     * @param  string  $message
     * @param  array  $context
     * @return void
     */
    public function log(string $level, string $message, array $context = []): void
    {
        Log::channel('payable')->{$level}($message, $context);
    }

    /**
     * Log debug message.
     *
     * @param  string  $message
     * @param  array  $context
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Log info message.
     *
     * @param  string  $message
     * @param  array  $context
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log warning message.
     *
     * @param  string  $message
     * @param  array  $context
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Log error message.
     *
     * @param  string  $message
     * @param  array  $context
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Log critical message.
     *
     * @param  string  $message
     * @param  array  $context
     * @return void
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * Log alert message.
     *
     * @param  string  $message
     * @param  array  $context
     * @return void
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * Log emergency message.
     *
     * @param  string  $message
     * @param  array  $context
     * @return void
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * Load processors from configuration.
     *
     * @return void
     */
    protected function loadProcessors(): void
    {
        $this->processors = Config::get('payable.processors', []);
    }
}
