<?php

namespace Ideacrafters\EloquentPayable\Facades;

use Illuminate\Support\Facades\Facade;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Contracts\PaymentProcessor;

/**
 * @method static Payment process($payable, $payer, float $amount, array $options = [])
 * @method static Payment processOffline($payable, $payer, float $amount, array $options = [])
 * @method static Payment refund(Payment $payment, ?float $amount = null)
 * @method static PaymentProcessor getProcessor(string $name = null)
 * @method static array getProcessors()
 * @method static string getDefaultProcessor()
 * @method static array getPaymentUrls(Payment $payment)
 * @method static Payment find(int $id)
 * @method static \Illuminate\Database\Eloquent\Collection getPaymentsFor($payable)
 * @method static \Illuminate\Database\Eloquent\Collection getPaymentsBy($payer)
 * @method static float getTotalPaidBy($payer)
 * @method static float getTotalPaidFor($payable)
 * @method static bool hasPaidFor($payable, $payer)
 * @method static Payment getLatestPaymentFor($payable, $payer)
 * @method static \Illuminate\Database\Eloquent\Collection getCompletedPayments()
 * @method static \Illuminate\Database\Eloquent\Collection getPendingPayments()
 * @method static \Illuminate\Database\Eloquent\Collection getFailedPayments()
 * @method static \Illuminate\Database\Eloquent\Collection getOfflinePayments()
 * @method static \Illuminate\Database\Eloquent\Collection getPaymentsToday()
 * @method static \Illuminate\Database\Eloquent\Collection getPaymentsThisMonth()
 * @method static array getPaymentStats()
 * @method static array getProcessorStats()
 * @method static void markAsPaid(Payment $payment, $paidAt = null)
 * @method static void markAsFailed(Payment $payment, string $reason = null)
 * @method static void markAsPending(Payment $payment)
 * @method static bool isCompleted(Payment $payment)
 * @method static bool isPending(Payment $payment)
 * @method static bool isFailed(Payment $payment)
 * @method static bool isOffline(Payment $payment)
 * @method static float getRefundableAmount(Payment $payment)
 * @method static array getWebhookUrls()
 * @method static array getCallbackUrls()
 * @method static void handleWebhook(string $processor, array $payload)
 * @method static array getSupportedCurrencies()
 * @method static string getDefaultCurrency()
 * @method static int getDecimalPrecision()
 * @method static array getPaymentStatuses()
 * @method static array getProcessorNames()
 * @method static bool isProcessorSupported(string $name)
 * @method static void setDefaultProcessor(string $name)
 * @method static void registerProcessor(string $name, string $class)
 * @method static void unregisterProcessor(string $name)
 * @method static array getConfiguration()
 * @method static void setConfiguration(array $config)
 * @method static void resetConfiguration()
 * @method static void clearCache()
 * @method static void warmCache()
 * @method static array getMetrics()
 * @method static array getHealthCheck()
 * @method static void log(string $level, string $message, array $context = [])
 * @method static void debug(string $message, array $context = [])
 * @method static void info(string $message, array $context = [])
 * @method static void warning(string $message, array $context = [])
 * @method static void error(string $message, array $context = [])
 * @method static void critical(string $message, array $context = [])
 * @method static void alert(string $message, array $context = [])
 * @method static void emergency(string $message, array $context = [])
 *
 * @see \Ideacrafters\EloquentPayable\PayableManager
 */
class Payable extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'payable';
    }
}
