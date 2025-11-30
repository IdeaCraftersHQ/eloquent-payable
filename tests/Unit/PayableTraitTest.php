<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

require_once __DIR__ . '/TestCaseHelpers.php';

use Ideacrafters\EloquentPayable\Tests\TestCase;
use Ideacrafters\EloquentPayable\Tests\Unit\TestInvoice;
use Ideacrafters\EloquentPayable\Tests\Unit\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PayableTraitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tables
        \Illuminate\Support\Facades\Schema::create('test_invoices', function ($table) {
            $table->id();
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });

        \Illuminate\Support\Facades\Schema::create('test_payers', function ($table) {
            $table->id();
            $table->timestamps();
        });
    }

    /** @test */
    public function model_can_accept_payments()
    {
        $invoice = new TestInvoice(['amount' => 100.00]);
        $invoice->save();
        $user = new TestUser(['id' => 1]);
        $user->save();

        $payment = $invoice->pay($user, 100.00, ['processor' => 'none']);

        $this->assertEquals('completed', $payment->status);
        $this->assertEquals(100.00, $payment->amount);
        $this->assertEquals('none', $payment->processor);
    }

    /** @test */
    public function can_process_offline_payments()
    {
        $invoice = new TestInvoice(['amount' => 100.00]);
        $invoice->save();
        $user = new TestUser(['id' => 1]);
        $user->save();

        $payment = $invoice->payOffline($user, 100.00, [
            'type' => 'check',
            'reference' => 'CHK-123'
        ]);

        $this->assertEquals('pending', $payment->status);
        $this->assertEquals('offline', $payment->processor);
        // Offline processor uses the provided reference if given
        $this->assertEquals('CHK-123', $payment->reference);
    }

    /** @test */
    public function can_get_payment_relationships()
    {
        $invoice = new TestInvoice(['amount' => 100.00]);
        $invoice->save();
        $user = new TestUser(['id' => 1]);
        $user->save();

        $invoice->pay($user, 100.00, ['processor' => 'none']);
        $invoice->payOffline($user, 50.00);

        $this->assertCount(2, $invoice->payments);
        $this->assertCount(1, $invoice->completedPayments);
        $this->assertCount(1, $invoice->pendingPayments);
    }
}
