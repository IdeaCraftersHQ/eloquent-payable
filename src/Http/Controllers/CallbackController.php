<?php

namespace Ideacrafters\EloquentPayable\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Events\PaymentCompleted;
use Ideacrafters\EloquentPayable\Events\PaymentFailed;
use Illuminate\Routing\Controller;

class CallbackController extends Controller
{
    /**
     * Handle successful payment callback.
     *
     * @param  Request  $request
     * @return View
     */
    public function success(Request $request): View
    {
        $payment = $this->getPaymentFromRequest($request);
        
        if ($payment && $payment->isPending()) {
            $payment->markAsPaid();
        }

        return view('payable::callback.success', compact('payment'));
    }

    /**
     * Handle cancelled payment callback.
     *
     * @param  Request  $request
     * @return View
     */
    public function cancel(Request $request): View
    {
        $payment = $this->getPaymentFromRequest($request);
        
        if ($payment && $payment->isPending()) {
            $processor = $payment->getProcessor();
            
            // If processor supports cancellation, mark as canceled; otherwise mark as failed
            if ($processor->supportsCancellation()) {
                $payment->markAsCanceled('Payment was cancelled by user');
            } else {
            $payment->markAsFailed('Payment was cancelled by user');
            }
        }

        return view('payable::callback.cancel', compact('payment'));
    }

    /**
     * Handle failed payment callback.
     *
     * @param  Request  $request
     * @return View
     */
    public function failed(Request $request): View
    {
        $payment = $this->getPaymentFromRequest($request);
        
        if ($payment && $payment->isPending()) {
            $payment->markAsFailed('Payment failed');
        }

        return view('payable::callback.failed', compact('payment'));
    }

    /**
     * Get payment from request parameters.
     *
     * @param  Request  $request
     * @return Payment|null
     */
    protected function getPaymentFromRequest(Request $request): ?Payment
    {
        $paymentId = $request->get('payment');
        
        if (!$paymentId) {
            return null;
        }

        return Payment::find($paymentId);
    }
}
