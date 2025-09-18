<?php

namespace Ideacrafters\EloquentPayable\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Ideacrafters\EloquentPayable\Events\PaymentCompleted;
use Ideacrafters\EloquentPayable\Events\PaymentFailed;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;

class WebhookController extends Controller
{
    /**
     * Handle Stripe webhooks.
     *
     * @param  Request  $request
     * @return Response
     */
    public function stripe(Request $request): Response
    {
        try {
            $processor = app(\Ideacrafters\EloquentPayable\Processors\StripeProcessor::class);
            $result = $processor->handleWebhook([
                'body' => $request->getContent(),
                'signature' => $request->header('Stripe-Signature'),
            ]);

            return response('OK', 200);
        } catch (PaymentException $e) {
            return response($e->getMessage(), 400);
        } catch (\Exception $e) {
            return response('Webhook processing failed', 500);
        }
    }

    /**
     * Handle generic webhook processing.
     *
     * @param  Request  $request
     * @param  string  $processor
     * @return Response
     */
    public function handle(Request $request, string $processor): Response
    {
        try {
            $processors = config('payable.processors', []);
            $processorClass = $processors[$processor] ?? null;

            if (!$processorClass) {
                return response("Unknown processor: {$processor}", 400);
            }

            $processorInstance = app($processorClass);
            $result = $processorInstance->handleWebhook($request->all());

            return response('OK', 200);
        } catch (PaymentException $e) {
            return response($e->getMessage(), 400);
        } catch (\Exception $e) {
            return response('Webhook processing failed', 500);
        }
    }
}
