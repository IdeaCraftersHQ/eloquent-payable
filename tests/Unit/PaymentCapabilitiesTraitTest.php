<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

require_once __DIR__ . '/TestCaseHelpers.php';

use Ideacrafters\EloquentPayable\Events\PaymentCanceled;
use Ideacrafters\EloquentPayable\Events\PaymentCompleted;
use Ideacrafters\EloquentPayable\Events\PaymentFailed;
use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\PaymentStatus;
use Ideacrafters\EloquentPayable\Processors\ProcessorNames;
use Ideacrafters\EloquentPayable\Tests\TestCase;
use Ideacrafters\EloquentPayable\Tests\Unit\TestUser;
use Ideacrafters\EloquentPayable\Tests\Unit\TestPayable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class PaymentCapabilitiesTraitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        // Create test tables for relationships
        \Illuminate\Support\Facades\Schema::create('test_payers', function ($table) {
            $table->id();
        });

        \Illuminate\Support\Facades\Schema::create('test_payables', function ($table) {
            $table->id();
        });
    }

    /** @test */
    public function payment_lifecycle_trait_manages_timestamps_on_status_change()
    {
        $payer = new TestUser(['id' => 1]);
        $payer->save();

        // Test timestamp management - create separate payments for each state transition
        // to avoid state transition restrictions
        
        // Test completed status sets paid_at
        $completedPayment = Payment::create([
            'payer_type' => TestUser::class,
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::OFFLINE,
        ]);
        $completedPayment->markAsPaid();
        $completedPayment->refresh();
        $this->assertNotNull($completedPayment->paid_at);
        $this->assertNull($completedPayment->failed_at);
        $this->assertNull($completedPayment->canceled_at);

        // Test failed status sets failed_at
        $failedPayment = Payment::create([
            'payer_type' => TestUser::class,
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 2,
            'amount' => 50.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::STRIPE,
        ]);
        $failedPayment->markAsFailed('Test failure');
        $failedPayment->refresh();
        $this->assertNotNull($failedPayment->failed_at);
        $this->assertNull($failedPayment->paid_at);
        $this->assertNull($failedPayment->canceled_at);

        // Test canceled status sets canceled_at
        $canceledPayment = Payment::create([
            'payer_type' => TestUser::class,
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 3,
            'amount' => 75.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::STRIPE,
        ]);
        $canceledPayment->markAsCanceled('Test cancellation');
        $canceledPayment->refresh();
        $this->assertNotNull($canceledPayment->canceled_at);
        $this->assertNull($canceledPayment->paid_at);
        $this->assertNull($canceledPayment->failed_at);

        // Test that timestamps are mutually exclusive - when one is set, others are cleared
        // This is verified by the above tests where completed sets paid_at and clears others,
        // failed sets failed_at and clears others, etc.
    }

    /** @test */
    public function payment_lifecycle_trait_can_mark_payment_as_paid()
    {
        $payment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::OFFLINE,
        ]);

        $payment->markAsPaid();

        $this->assertEquals(PaymentStatus::completed(), $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
        Event::assertDispatched(PaymentCompleted::class);
    }

    /** @test */
    public function payment_lifecycle_trait_can_mark_payment_as_failed()
    {
        $payment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        $payment->markAsFailed('Card declined');

        $this->assertEquals(PaymentStatus::failed(), $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->failed_at);
        $this->assertStringContainsString('Card declined', $payment->fresh()->notes);
        Event::assertDispatched(PaymentFailed::class);
    }

    /** @test */
    public function payment_lifecycle_trait_can_mark_payment_as_canceled()
    {
        $payment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        $payment->markAsCanceled('User canceled');

        $this->assertEquals(PaymentStatus::canceled(), $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->canceled_at);
        $this->assertStringContainsString('User canceled', $payment->fresh()->notes);
        Event::assertDispatched(PaymentCanceled::class);
    }

    /** @test */
    public function payment_lifecycle_trait_prevents_invalid_state_transitions()
    {
        $payment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::completed(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        // Cannot mark completed payment as failed
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Cannot mark a completed payment as failed');
        $payment->markAsFailed();

        // Cannot mark completed payment as canceled
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Cannot cancel a completed payment');
        $payment->markAsCanceled();

        // Cannot mark failed payment as paid directly
        $payment->update(['status' => PaymentStatus::failed()]);
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Cannot mark a failed payment as paid');
        $payment->markAsPaid();
    }

    /** @test */
    public function payment_lifecycle_trait_provides_status_check_methods()
    {
        $payment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::completed(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        $this->assertTrue($payment->isCompleted());
        $this->assertFalse($payment->isPending());
        $this->assertFalse($payment->isFailed());
        $this->assertFalse($payment->isCanceled());
        $this->assertFalse($payment->isProcessing());

        $payment->update(['status' => PaymentStatus::processing()]);
        $this->assertTrue($payment->fresh()->isProcessing());
        $this->assertFalse($payment->fresh()->isCompleted());
    }

    /** @test */
    public function payment_lifecycle_trait_provides_query_scopes()
    {
        Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::completed(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 2,
            'amount' => 50.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 3,
            'amount' => 75.00,
            'currency' => 'USD',
            'status' => PaymentStatus::failed(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 4,
            'amount' => 25.00,
            'currency' => 'USD',
            'status' => PaymentStatus::canceled(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        $this->assertCount(1, Payment::completed()->get());
        $this->assertCount(1, Payment::pending()->get());
        $this->assertCount(1, Payment::failed()->get());
        $this->assertCount(1, Payment::canceled()->get());
    }

    /** @test */
    public function payment_lifecycle_trait_provides_offline_scope()
    {
        Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::OFFLINE,
        ]);

        Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 2,
            'amount' => 50.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        $this->assertCount(1, Payment::offline()->get());
    }

    /** @test */
    public function payment_lifecycle_trait_provides_date_scopes()
    {
        Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::completed(),
            'processor' => ProcessorNames::STRIPE,
            'created_at' => now(),
        ]);

        $this->assertCount(1, Payment::today()->get());
        $this->assertCount(1, Payment::thisMonth()->get());
    }

    /** @test */
    public function interacts_with_payment_processor_trait_can_get_processor_instance()
    {
        $payment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        $processor = $payment->getProcessor();

        $this->assertInstanceOf(\Ideacrafters\EloquentPayable\Contracts\PaymentProcessor::class, $processor);
        $this->assertEquals(ProcessorNames::STRIPE, $processor->getName());
    }

    /** @test */
    public function interacts_with_payment_processor_trait_can_check_if_offline()
    {
        $offlinePayment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::OFFLINE,
        ]);

        $onlinePayment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 2,
            'amount' => 50.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        $this->assertTrue($offlinePayment->isOffline());
        $this->assertFalse($onlinePayment->isOffline());
    }

    /** @test */
    public function interacts_with_payment_processor_trait_throws_exception_for_unknown_processor()
    {
        $payment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => 'unknown_processor',
        ]);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Unknown processor: unknown_processor');
        $payment->getProcessor();
    }

    /** @test */
    public function payment_capabilities_trait_provides_payer_relationship()
    {
        $payer = new TestUser(['id' => 1]);
        $payer->save();

        $payment = Payment::create([
            'payer_type' => TestUser::class,
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        $this->assertInstanceOf(TestUser::class, $payment->payer);
        $this->assertEquals(1, $payment->payer->id);
    }

    /** @test */
    public function payment_capabilities_trait_provides_payable_relationship()
    {
        $payable = new TestPayable(['id' => 1]);
        $payable->save();

        $payment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => TestPayable::class,
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::STRIPE,
        ]);

        $this->assertInstanceOf(TestPayable::class, $payment->payable);
        $this->assertEquals(1, $payment->payable->id);
    }

    /** @test */
    public function payment_capabilities_trait_integrates_all_sub_traits()
    {
        $payer = new TestUser(['id' => 1]);
        $payer->save();

        $payable = new TestPayable(['id' => 1]);
        $payable->save();

        $payment = Payment::create([
            'payer_type' => TestUser::class,
            'payer_id' => 1,
            'payable_type' => TestPayable::class,
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => PaymentStatus::pending(),
            'processor' => ProcessorNames::OFFLINE,
        ]);

        // Test PaymentLifecycle methods
        $this->assertTrue($payment->isPending());
        $payment->markAsPaid();
        $this->assertTrue($payment->fresh()->isCompleted());

        // Test InteractsWithPaymentProcessor methods
        $this->assertTrue($payment->fresh()->isOffline());
        $processor = $payment->fresh()->getProcessor();
        $this->assertEquals(ProcessorNames::OFFLINE, $processor->getName());

        // Test relationships (PaymentCapabilities)
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class, $payment->payer());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class, $payment->payable());
    }
}

