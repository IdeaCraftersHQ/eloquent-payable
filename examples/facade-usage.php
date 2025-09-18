<?php

/**
 * Eloquent Payable Facade Usage Examples
 * 
 * This file demonstrates how to use the Payable Facade for various
 * payment operations in your Laravel application.
 */

use Ideacrafters\EloquentPayable\Facades\Payable;
use App\Models\Invoice;
use App\Models\User;

// ============================================================================
// BASIC PAYMENT PROCESSING
// ============================================================================

// Process a payment with Stripe
$invoice = Invoice::find(1);
$user = User::find(1);

$payment = Payable::process($invoice, $user, 100.00, [
    'processor' => 'stripe',
    'payment_method_id' => 'pm_card_visa'
]);

// Process an offline payment
$offlinePayment = Payable::processOffline($invoice, $user, 100.00, [
    'type' => 'bank_transfer',
    'reference' => 'TXN-123456'
]);

// Process a free item
$freeItem = Payable::process($invoice, $user, 0.00, [
    'processor' => 'none'
]);

// ============================================================================
// PAYMENT MANAGEMENT
// ============================================================================

// Find a payment
$payment = Payable::find(1);

// Mark payments as paid/failed
Payable::markAsPaid($payment);
Payable::markAsFailed($payment, 'Card declined');
Payable::markAsPending($payment);

// Refund payments
Payable::refund($payment, 50.00); // Partial refund
Payable::refund($payment); // Full refund

// ============================================================================
// PAYMENT QUERIES
// ============================================================================

// Get payments for a specific item
$invoicePayments = Payable::getPaymentsFor($invoice);

// Get payments by a specific user
$userPayments = Payable::getPaymentsBy($user);

// Get total amounts
$totalPaidByUser = Payable::getTotalPaidBy($user);
$totalPaidForInvoice = Payable::getTotalPaidFor($invoice);

// Check payment status
$hasPaid = Payable::hasPaidFor($invoice, $user);
$latestPayment = Payable::getLatestPaymentFor($invoice, $user);

// ============================================================================
// PAYMENT COLLECTIONS
// ============================================================================

// Get different types of payments
$completedPayments = Payable::getCompletedPayments();
$pendingPayments = Payable::getPendingPayments();
$failedPayments = Payable::getFailedPayments();
$offlinePayments = Payable::getOfflinePayments();

// Get time-based payments
$todayPayments = Payable::getPaymentsToday();
$monthPayments = Payable::getPaymentsThisMonth();

// ============================================================================
// STATISTICS & ANALYTICS
// ============================================================================

// Get payment statistics
$stats = Payable::getPaymentStats();
/*
Returns:
[
    'total' => 150,
    'completed' => 120,
    'pending' => 20,
    'failed' => 10,
    'offline' => 30,
    'total_amount' => 15000.00,
    'refunded_amount' => 500.00,
    'today' => 5,
    'this_month' => 45
]
*/

// Get processor statistics
$processorStats = Payable::getProcessorStats();
/*
Returns:
[
    'stripe' => [
        'total' => 100,
        'completed' => 95,
        'pending' => 3,
        'failed' => 2,
        'total_amount' => 10000.00
    ],
    'offline' => [
        'total' => 30,
        'completed' => 25,
        'pending' => 5,
        'failed' => 0,
        'total_amount' => 3000.00
    ]
]
*/

// Get all metrics
$metrics = Payable::getMetrics();

// Health check
$health = Payable::getHealthCheck();
/*
Returns:
[
    'status' => 'healthy',
    'issues' => [],
    'timestamp' => '2024-01-01T12:00:00.000000Z'
]
*/

// ============================================================================
// CONFIGURATION & MANAGEMENT
// ============================================================================

// Get configuration
$config = Payable::getConfiguration();

// Get supported currencies
$currencies = Payable::getSupportedCurrencies();
// ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK']

// Get default currency
$defaultCurrency = Payable::getDefaultCurrency(); // 'USD'

// Get available processors
$processors = Payable::getProcessorNames();
// ['stripe', 'offline', 'none']

// Check processor support
$isStripeSupported = Payable::isProcessorSupported('stripe'); // true
$isPayPalSupported = Payable::isProcessorSupported('paypal'); // false

// Set default processor
Payable::setDefaultProcessor('stripe');

// ============================================================================
// UTILITY METHODS
// ============================================================================

// Get payment URLs
$urls = Payable::getPaymentUrls($payment);
/*
Returns:
[
    'success' => 'https://yourapp.com/payable/callback/success?payment=1',
    'cancel' => 'https://yourapp.com/payable/callback/cancel?payment=1',
    'failed' => 'https://yourapp.com/payable/callback/failed?payment=1'
]
*/

// Get webhook URLs
$webhookUrls = Payable::getWebhookUrls();
/*
Returns:
[
    'stripe' => 'https://yourapp.com/payable/webhooks/stripe',
    'generic' => 'https://yourapp.com/payable/webhooks/{processor}'
]
*/

// Get callback URLs
$callbackUrls = Payable::getCallbackUrls();

// Cache management
Payable::clearCache();
Payable::warmCache();

// ============================================================================
// LOGGING
// ============================================================================

// Log payment events
Payable::info('Payment processed successfully', [
    'payment_id' => $payment->id,
    'amount' => $payment->amount,
    'processor' => $payment->processor
]);

Payable::error('Payment failed', [
    'payment_id' => $payment->id,
    'error' => 'Card declined',
    'processor' => $payment->processor
]);

Payable::debug('Payment processing started', [
    'payable_type' => get_class($invoice),
    'payable_id' => $invoice->id,
    'payer_type' => get_class($user),
    'payer_id' => $user->id
]);

// ============================================================================
// REAL-WORLD EXAMPLES
// ============================================================================

// Example 1: E-commerce checkout
function processCheckout($cart, $user, $paymentMethodId) {
    $total = $cart->getTotal();
    
    $payment = Payable::process($cart, $user, $total, [
        'processor' => 'stripe',
        'payment_method_id' => $paymentMethodId
    ]);
    
    if ($payment->isCompleted()) {
        Payable::info('Checkout completed', [
            'cart_id' => $cart->id,
            'user_id' => $user->id,
            'amount' => $total
        ]);
        
        return $payment;
    }
    
    Payable::error('Checkout failed', [
        'cart_id' => $cart->id,
        'user_id' => $user->id,
        'amount' => $total
    ]);
    
    return null;
}

// Example 2: Invoice payment tracking
function trackInvoicePayments($invoice) {
    $stats = [
        'total_invoiced' => $invoice->total_amount,
        'total_paid' => Payable::getTotalPaidFor($invoice),
        'remaining' => $invoice->total_amount - Payable::getTotalPaidFor($invoice),
        'payment_count' => Payable::getPaymentsFor($invoice)->count(),
        'is_fully_paid' => Payable::getTotalPaidFor($invoice) >= $invoice->total_amount
    ];
    
    return $stats;
}

// Example 3: User payment history
function getUserPaymentHistory($user) {
    return [
        'total_paid' => Payable::getTotalPaidBy($user),
        'payment_count' => Payable::getPaymentsBy($user)->count(),
        'completed_payments' => Payable::getPaymentsBy($user)->where('status', 'completed')->count(),
        'pending_payments' => Payable::getPaymentsBy($user)->where('status', 'pending')->count(),
        'failed_payments' => Payable::getPaymentsBy($user)->where('status', 'failed')->count(),
        'this_month' => Payable::getPaymentsBy($user)->where('created_at', '>=', now()->startOfMonth())->count()
    ];
}

// Example 4: Admin dashboard metrics
function getAdminMetrics() {
    $paymentStats = Payable::getPaymentStats();
    $processorStats = Payable::getProcessorStats();
    $health = Payable::getHealthCheck();
    
    return [
        'overview' => $paymentStats,
        'processors' => $processorStats,
        'health' => $health,
        'revenue' => $paymentStats['total_amount'] - $paymentStats['refunded_amount'],
        'conversion_rate' => $paymentStats['completed'] / max($paymentStats['total'], 1) * 100
    ];
}

// Example 5: Payment processor switching
function switchToOfflinePayment($payment) {
    if ($payment->isPending() && $payment->processor === 'stripe') {
        // Mark original payment as failed
        Payable::markAsFailed($payment, 'Switched to offline payment');
        
        // Create new offline payment
        $offlinePayment = Payable::processOffline(
            $payment->payable,
            $payment->payer,
            $payment->amount,
            [
                'reference' => 'OFFLINE-' . $payment->id,
                'notes' => 'Converted from Stripe payment'
            ]
        );
        
        Payable::info('Payment converted to offline', [
            'original_payment_id' => $payment->id,
            'offline_payment_id' => $offlinePayment->id
        ]);
        
        return $offlinePayment;
    }
    
    return $payment;
}
