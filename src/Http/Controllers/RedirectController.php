<?php

namespace Ideacrafters\EloquentPayable\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Facades\Payable;
use Ideacrafters\EloquentPayable\Events\PaymentCompleted;
use Ideacrafters\EloquentPayable\Events\PaymentFailed;

class RedirectController extends Controller
{
    /**
     * Handle successful payment redirect.
     *
     * @param  Request  $request
     * @return RedirectResponse|View
     */
    public function success(Request $request)
    {
        $payment = $this->getPaymentFromRequest($request);
        
        if (!$payment) {
            return $this->handleInvalidPayment('Payment not found');
        }

        // Complete the redirect payment
        try {
            $completedPayment = Payable::completeRedirect($payment, $request->all());
            
            if ($completedPayment->isCompleted()) {
                event(new PaymentCompleted($completedPayment));
                
                return $this->handleSuccessfulPayment($completedPayment, $request);
            } else {
                return $this->handleFailedPayment($completedPayment, 'Payment was not completed', $request);
            }
        } catch (\Exception $e) {
            return $this->handleFailedPayment($payment, $e->getMessage(), $request);
        }
    }

    /**
     * Handle cancelled payment redirect.
     *
     * @param  Request  $request
     * @return RedirectResponse|View
     */
    public function cancel(Request $request)
    {
        $payment = $this->getPaymentFromRequest($request);
        
        if (!$payment) {
            return $this->handleInvalidPayment('Payment not found');
        }

        // Mark payment as failed if it's still pending
        if ($payment->isPending()) {
            $payment->markAsFailed('Payment was cancelled by user');
            event(new PaymentFailed($payment));
        }

        return $this->handleCancelledPayment($payment, $request);
    }

    /**
     * Handle failed payment redirect.
     *
     * @param  Request  $request
     * @return RedirectResponse|View
     */
    public function failed(Request $request)
    {
        $payment = $this->getPaymentFromRequest($request);
        
        if (!$payment) {
            return $this->handleInvalidPayment('Payment not found');
        }

        // Mark payment as failed if it's still pending
        if ($payment->isPending()) {
            $payment->markAsFailed('Payment failed');
            event(new PaymentFailed($payment));
        }

        return $this->handleFailedPayment($payment, 'Payment failed', $request);
    }

    /**
     * Get payment from request parameters.
     *
     * @param  Request  $request
     * @return Payment|null
     */
    protected function getPaymentFromRequest(Request $request): ?Payment
    {
        $paymentId = $request->get('payment') ?? $request->get('payment_id');
        
        if (!$paymentId) {
            return null;
        }

        return Payment::find($paymentId);
    }

    /**
     * Handle successful payment.
     *
     * @param  Payment  $payment
     * @param  Request  $request
     * @return RedirectResponse|View
     */
    protected function handleSuccessfulPayment(Payment $payment, Request $request)
    {
        // Check if there's a custom success URL in the payment metadata
        $successUrl = $payment->metadata['success_url'] ?? null;
        
        if ($successUrl) {
            return redirect($successUrl);
        }

        // Check if there's a success URL in the request
        $successUrl = $request->get('success_url');
        
        if ($successUrl) {
            return redirect($successUrl);
        }

        // Use default success view
        return view('payable::redirect.success', compact('payment'));
    }

    /**
     * Handle cancelled payment.
     *
     * @param  Payment  $payment
     * @param  Request  $request
     * @return RedirectResponse|View
     */
    protected function handleCancelledPayment(Payment $payment, Request $request)
    {
        // Check if there's a custom cancel URL in the payment metadata
        $cancelUrl = $payment->metadata['cancel_url'] ?? null;
        
        if ($cancelUrl) {
            return redirect($cancelUrl);
        }

        // Check if there's a cancel URL in the request
        $cancelUrl = $request->get('cancel_url');
        
        if ($cancelUrl) {
            return redirect($cancelUrl);
        }

        // Use default cancel view
        return view('payable::redirect.cancel', compact('payment'));
    }

    /**
     * Handle failed payment.
     *
     * @param  Payment  $payment
     * @param  string  $reason
     * @param  Request  $request
     * @return RedirectResponse|View
     */
    protected function handleFailedPayment(Payment $payment, string $reason, Request $request)
    {
        // Check if there's a custom failure URL in the payment metadata
        $failureUrl = $payment->metadata['failure_url'] ?? null;
        
        if ($failureUrl) {
            return redirect($failureUrl);
        }

        // Check if there's a failure URL in the request
        $failureUrl = $request->get('failure_url');
        
        if ($failureUrl) {
            return redirect($failureUrl);
        }

        // Use default failure view
        return view('payable::redirect.failed', compact('payment', 'reason'));
    }

    /**
     * Handle invalid payment.
     *
     * @param  string  $reason
     * @return View
     */
    protected function handleInvalidPayment(string $reason): View
    {
        return view('payable::redirect.error', compact('reason'));
    }
}
