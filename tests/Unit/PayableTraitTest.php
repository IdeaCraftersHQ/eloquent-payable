<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

use Ideacrafters\EloquentPayable\Tests\TestCase;
use Ideacrafters\EloquentPayable\Traits\Payable;
use Ideacrafters\EloquentPayable\Contracts\Payable as PayableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PayableTraitTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function model_can_accept_payments()
    {
        $invoice = new TestInvoice(['amount' => 100.00]);
        $user = new TestUser(['id' => 1]);

        $payment = $invoice->pay($user, 100.00);

        $this->assertEquals('completed', $payment->status);
        $this->assertEquals(100.00, $payment->amount);
        $this->assertEquals('none', $payment->processor);
    }

    /** @test */
    public function can_process_offline_payments()
    {
        $invoice = new TestInvoice(['amount' => 100.00]);
        $user = new TestUser(['id' => 1]);

        $payment = $invoice->payOffline($user, 100.00, [
            'type' => 'check',
            'reference' => 'CHK-123'
        ]);

        $this->assertEquals('pending', $payment->status);
        $this->assertEquals('offline', $payment->processor);
        $this->assertStringStartsWith('OFFLINE-', $payment->reference);
    }

    /** @test */
    public function can_get_payment_relationships()
    {
        $invoice = new TestInvoice(['amount' => 100.00]);
        $user = new TestUser(['id' => 1]);

        $invoice->pay($user, 100.00);
        $invoice->payOffline($user, 50.00);

        $this->assertCount(2, $invoice->payments);
        $this->assertCount(1, $invoice->completedPayments);
        $this->assertCount(1, $invoice->pendingPayments);
    }
}

class TestInvoice extends Model implements PayableContract
{
    use Payable;

    protected $fillable = ['amount'];

    public function getPayableAmount($payer = null): float
    {
        return $this->amount;
    }

    public function isPayableBy($payer): bool
    {
        return true;
    }
}

class TestUser extends Model
{
    protected $fillable = ['id'];
}
