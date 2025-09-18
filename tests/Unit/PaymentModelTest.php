<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

use Ideacrafters\EloquentPayable\Tests\TestCase;
use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function can_mark_payment_as_paid()
    {
        $payment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => 'pending',
            'processor' => 'offline',
        ]);

        $payment->markAsPaid();

        $this->assertEquals('completed', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
    }

    /** @test */
    public function can_mark_payment_as_failed()
    {
        $payment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => 'pending',
            'processor' => 'stripe',
        ]);

        $payment->markAsFailed('Card declined');

        $this->assertEquals('failed', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->failed_at);
        $this->assertStringContains('Card declined', $payment->fresh()->notes);
    }

    /** @test */
    public function can_check_payment_status()
    {
        $payment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => 'completed',
            'processor' => 'stripe',
        ]);

        $this->assertTrue($payment->isCompleted());
        $this->assertFalse($payment->isPending());
        $this->assertFalse($payment->isFailed());
    }

    /** @test */
    public function can_calculate_refundable_amount()
    {
        $payment = Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => 'completed',
            'processor' => 'stripe',
            'refunded_amount' => 30.00,
        ]);

        $this->assertEquals(70.00, $payment->getRefundableAmount());
    }
}
