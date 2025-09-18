<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

use Ideacrafters\EloquentPayable\Tests\TestCase;
use Ideacrafters\EloquentPayable\Facades\Payable;
use Ideacrafters\EloquentPayable\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PayableFacadeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function facade_can_process_payments()
    {
        $invoice = new TestInvoice(['amount' => 100.00]);
        $user = new TestUser(['id' => 1]);

        $payment = Payable::process($invoice, $user, 100.00);

        $this->assertEquals('completed', $payment->status);
        $this->assertEquals(100.00, $payment->amount);
    }

    /** @test */
    public function facade_can_process_offline_payments()
    {
        $invoice = new TestInvoice(['amount' => 100.00]);
        $user = new TestUser(['id' => 1]);

        $payment = Payable::processOffline($invoice, $user, 100.00);

        $this->assertEquals('pending', $payment->status);
        $this->assertEquals('offline', $payment->processor);
    }

    /** @test */
    public function facade_can_get_payment_stats()
    {
        // Create some test payments
        Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => 'completed',
            'processor' => 'stripe',
        ]);

        $stats = Payable::getPaymentStats();

        $this->assertEquals(1, $stats['total']);
        $this->assertEquals(1, $stats['completed']);
        $this->assertEquals(100.00, $stats['total_amount']);
    }

    /** @test */
    public function facade_can_get_processor_stats()
    {
        // Create some test payments
        Payment::create([
            'payer_type' => 'TestUser',
            'payer_id' => 1,
            'payable_type' => 'TestInvoice',
            'payable_id' => 1,
            'amount' => 100.00,
            'currency' => 'USD',
            'status' => 'completed',
            'processor' => 'stripe',
        ]);

        $stats = Payable::getProcessorStats();

        $this->assertArrayHasKey('stripe', $stats);
        $this->assertEquals(1, $stats['stripe']['total']);
        $this->assertEquals(100.00, $stats['stripe']['total_amount']);
    }

    /** @test */
    public function facade_can_get_health_check()
    {
        $health = Payable::getHealthCheck();

        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('issues', $health);
        $this->assertArrayHasKey('timestamp', $health);
    }

    /** @test */
    public function facade_can_get_configuration()
    {
        $config = Payable::getConfiguration();

        $this->assertArrayHasKey('default_processor', $config);
        $this->assertArrayHasKey('processors', $config);
        $this->assertArrayHasKey('currency', $config);
    }

    /** @test */
    public function facade_can_log_messages()
    {
        // This test just ensures the method doesn't throw an exception
        Payable::info('Test message', ['context' => 'test']);
        Payable::debug('Debug message');
        Payable::warning('Warning message');
        Payable::error('Error message');
        
        $this->assertTrue(true);
    }

    /** @test */
    public function facade_can_get_supported_currencies()
    {
        $currencies = Payable::getSupportedCurrencies();

        $this->assertIsArray($currencies);
        $this->assertContains('USD', $currencies);
        $this->assertContains('EUR', $currencies);
    }

    /** @test */
    public function facade_can_get_processor_names()
    {
        $processors = Payable::getProcessorNames();

        $this->assertIsArray($processors);
        $this->assertContains('stripe', $processors);
        $this->assertContains('offline', $processors);
        $this->assertContains('none', $processors);
    }

    /** @test */
    public function facade_can_check_processor_support()
    {
        $this->assertTrue(Payable::isProcessorSupported('stripe'));
        $this->assertTrue(Payable::isProcessorSupported('offline'));
        $this->assertTrue(Payable::isProcessorSupported('none'));
        $this->assertFalse(Payable::isProcessorSupported('nonexistent'));
    }
}

class TestInvoice extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['amount'];
}

class TestUser extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['id'];
}
