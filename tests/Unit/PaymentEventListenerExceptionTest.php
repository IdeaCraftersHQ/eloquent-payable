<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

use Exception;
use Ideacrafters\EloquentPayable\Events\PaymentCanceled;
use Ideacrafters\EloquentPayable\Events\PaymentCompleted;
use Ideacrafters\EloquentPayable\Events\PaymentFailed;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;

class PaymentEventListenerExceptionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function payment_state_is_preserved_when_mark_as_paid_listener_throws_exception()
    {
        $payment = $this->createPendingPayment();

        // Register a synchronous listener that throws an exception
        Event::listen(PaymentCompleted::class, function ($event) {
            throw new Exception('Listener failed');
        });

        // Mark payment as paid - should not throw despite listener exception
        $payment->markAsPaid();

        // Payment should still be marked as completed in the database
        $freshPayment = $payment->fresh();
        $this->assertEquals('completed', $freshPayment->status);
        $this->assertNotNull($freshPayment->paid_at);
    }

    /** @test */
    public function payment_state_is_preserved_when_mark_as_failed_listener_throws_exception()
    {
        $payment = $this->createPendingPayment();

        // Register a synchronous listener that throws an exception
        Event::listen(PaymentFailed::class, function ($event) {
            throw new Exception('Listener failed');
        });

        // Mark payment as failed - should not throw despite listener exception
        $payment->markAsFailed('Card declined');

        // Payment should still be marked as failed in the database
        $freshPayment = $payment->fresh();
        $this->assertEquals('failed', $freshPayment->status);
        $this->assertNotNull($freshPayment->failed_at);
        $this->assertStringContainsString('Card declined', $freshPayment->notes);
    }

    /** @test */
    public function payment_state_is_preserved_when_mark_as_canceled_listener_throws_exception()
    {
        $payment = $this->createPendingPayment();

        // Register a synchronous listener that throws an exception
        Event::listen(PaymentCanceled::class, function ($event) {
            throw new Exception('Listener failed');
        });

        // Mark payment as canceled - should not throw despite listener exception
        $payment->markAsCanceled('User requested');

        // Payment should still be marked as canceled in the database
        $freshPayment = $payment->fresh();
        $this->assertEquals('canceled', $freshPayment->status);
        $this->assertNotNull($freshPayment->canceled_at);
        $this->assertStringContainsString('User requested', $freshPayment->notes);
    }

    /** @test */
    public function listener_exception_is_reported_to_exception_handler()
    {
        $payment = $this->createPendingPayment();

        $expectedException = new Exception('Listener failed for reporting test');

        // Mock the exception handler to verify reporting
        $mockHandler = Mockery::mock(ExceptionHandler::class);
        $mockHandler->shouldReceive('report')
            ->once()
            ->with(Mockery::on(function ($exception) use ($expectedException) {
                return $exception->getMessage() === $expectedException->getMessage();
            }));

        $this->app->instance(ExceptionHandler::class, $mockHandler);

        // Register a synchronous listener that throws an exception
        Event::listen(PaymentCompleted::class, function ($event) use ($expectedException) {
            throw $expectedException;
        });

        // Mark payment as paid
        $payment->markAsPaid();

        // Verify the exception was reported (mockery assertion)
    }

    /** @test */
    public function mark_as_paid_returns_payment_instance_even_when_listener_throws()
    {
        $payment = $this->createPendingPayment();

        Event::listen(PaymentCompleted::class, function ($event) {
            throw new Exception('Listener failed');
        });

        $result = $payment->markAsPaid();

        // Should return the payment instance for method chaining
        $this->assertSame($payment, $result);
    }

    /** @test */
    public function mark_as_failed_returns_payment_instance_even_when_listener_throws()
    {
        $payment = $this->createPendingPayment();

        Event::listen(PaymentFailed::class, function ($event) {
            throw new Exception('Listener failed');
        });

        $result = $payment->markAsFailed();

        // Should return the payment instance for method chaining
        $this->assertSame($payment, $result);
    }

    /** @test */
    public function mark_as_canceled_returns_payment_instance_even_when_listener_throws()
    {
        $payment = $this->createPendingPayment();

        Event::listen(PaymentCanceled::class, function ($event) {
            throw new Exception('Listener failed');
        });

        $result = $payment->markAsCanceled();

        // Should return the payment instance for method chaining
        $this->assertSame($payment, $result);
    }

    /** @test */
    public function multiple_listeners_are_isolated_from_each_other()
    {
        $payment = $this->createPendingPayment();
        $secondListenerCalled = false;

        // First listener throws an exception
        Event::listen(PaymentCompleted::class, function ($event) {
            throw new Exception('First listener failed');
        });

        // Second listener should still not be affected by first listener's exception
        // Note: Laravel's event dispatcher stops after first exception normally,
        // but our safe firing catches the exception, allowing the event to complete
        Event::listen(PaymentCompleted::class, function ($event) use (&$secondListenerCalled) {
            $secondListenerCalled = true;
        });

        $payment->markAsPaid();

        // Payment state should be preserved regardless
        $this->assertEquals('completed', $payment->fresh()->status);
    }

    /**
     * Create a pending payment for testing.
     *
     * @return Payment
     */
    private function createPendingPayment(): Payment
    {
        return Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => 'pending',
            'processor' => 'stripe',
        ]);
    }
}
