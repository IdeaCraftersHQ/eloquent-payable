<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

require_once __DIR__ . '/TestCaseHelpers.php';

use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Ideacrafters\EloquentPayable\Processors\ProcessorNames;
use Ideacrafters\EloquentPayable\Processors\StripeProcessor;
use Ideacrafters\EloquentPayable\Processors\SlickpayProcessor;
use Ideacrafters\EloquentPayable\Processors\OfflineProcessor;
use Ideacrafters\EloquentPayable\Tests\TestCase;
use Ideacrafters\EloquentPayable\Tests\Unit\TestUser;
use Ideacrafters\EloquentPayable\Tests\Unit\TestPayable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

class ProcessorCurrencyValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test tables
        \Illuminate\Support\Facades\Schema::create('test_payers', function ($table) {
            $table->id();
            $table->timestamps();
        });

        \Illuminate\Support\Facades\Schema::create('test_payables', function ($table) {
            $table->id();
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamps();
        });

        // Set global currency config
        Config::set('payable.currency', 'USD');
    }

    /** @test */
    public function stripe_processor_supports_multiple_currencies()
    {
        $processor = new StripeProcessor();

        $this->assertTrue($processor->supportsMultipleCurrencies());
        $this->assertEquals('USD', $processor->getCurrency());
    }

    /** @test */
    public function stripe_processor_accepts_any_currency()
    {
        $processor = new StripeProcessor();
        $payable = new TestPayable(['amount' => 100.00]);
        $payable->save();
        $payer = new TestUser();
        $payer->save();

        // Should accept USD (default)
        $payment1 = $processor->process($payable, $payer, 100.00, ['currency' => 'USD']);
        $this->assertEquals('USD', $payment1->currency);

        // Should accept EUR
        $payment2 = $processor->process($payable, $payer, 100.00, ['currency' => 'EUR']);
        $this->assertEquals('EUR', $payment2->currency);

        // Should accept DZD
        $payment3 = $processor->process($payable, $payer, 100.00, ['currency' => 'DZD']);
        $this->assertEquals('DZD', $payment3->currency);
    }

    /** @test */
    public function slickpay_processor_only_supports_dzd()
    {
        $processor = new SlickpayProcessor();

        $this->assertFalse($processor->supportsMultipleCurrencies());
        $this->assertEquals('DZD', $processor->getCurrency());
    }

    /** @test */
    public function slickpay_processor_accepts_dzd_currency()
    {
        // Mock Slickpay API configuration to avoid actual API calls
        Config::set('payable.slickpay.api_key', 'test_key');
        Config::set('payable.slickpay.api_url', 'https://api.slickpay.test');

        $processor = new SlickpayProcessor();
        $payable = new TestPayable(['amount' => 100.00]);
        $payable->save();
        $payer = new TestUser();
        $payer->save();

        $options = ['success_url' => 'https://example.com/success'];

        // Should accept DZD (default)
        $payment1 = $processor->process($payable, $payer, 100.00, $options);
        $this->assertEquals('DZD', $payment1->currency);

        // Should accept DZD when explicitly provided
        $payment2 = $processor->process($payable, $payer, 100.00, array_merge($options, ['currency' => 'DZD']));
        $this->assertEquals('DZD', $payment2->currency);

        // Should accept lowercase dzd (case-insensitive)
        $payment3 = $processor->process($payable, $payer, 100.00, array_merge($options, ['currency' => 'dzd']));
        $this->assertEquals('dzd', $payment3->currency); // Stored as provided, but validation is case-insensitive
    }

    /** @test */
    public function slickpay_processor_rejects_non_dzd_currency()
    {
        $processor = new SlickpayProcessor();
        $payable = new TestPayable(['amount' => 100.00]);
        $payable->save();
        $payer = new TestUser();
        $payer->save();

        // Should reject USD
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage("Processor 'slickpay' only supports currency 'DZD'");
        $this->expectExceptionMessage("Currency 'USD' is not supported");

        $processor->process($payable, $payer, 100.00, [
            'currency' => 'USD',
            'success_url' => 'https://example.com/success'
        ]);
    }

    /** @test */
    public function slickpay_processor_rejects_eur_currency()
    {
        $processor = new SlickpayProcessor();
        $payable = new TestPayable(['amount' => 100.00]);
        $payable->save();
        $payer = new TestUser();
        $payer->save();

        // Should reject EUR
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage("Processor 'slickpay' only supports currency 'DZD'");
        $this->expectExceptionMessage("Currency 'EUR' is not supported");

        $processor->process($payable, $payer, 100.00, [
            'currency' => 'EUR',
            'success_url' => 'https://example.com/success'
        ]);
    }

    /** @test */
    public function currency_validation_is_case_insensitive()
    {
        // Mock Slickpay API configuration
        Config::set('payable.slickpay.api_key', 'test_key');
        Config::set('payable.slickpay.api_url', 'https://api.slickpay.test');

        $processor = new SlickpayProcessor();
        $payable = new TestPayable(['amount' => 100.00]);
        $payable->save();
        $payer = new TestUser();
        $payer->save();

        $options = ['success_url' => 'https://example.com/success'];

        // Should accept lowercase dzd
        $payment1 = $processor->process($payable, $payer, 100.00, array_merge($options, ['currency' => 'dzd']));
        $this->assertEquals('dzd', $payment1->currency);

        // Should accept mixed case Dzd
        $payment2 = $processor->process($payable, $payer, 100.00, array_merge($options, ['currency' => 'Dzd']));
        $this->assertEquals('Dzd', $payment2->currency);

        // Should reject lowercase usd (case-insensitive comparison)
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage("Currency 'usd' is not supported");

        $processor->process($payable, $payer, 100.00, array_merge($options, ['currency' => 'usd']));
    }

    /** @test */
    public function offline_processor_uses_global_currency_config()
    {
        Config::set('payable.currency', 'EUR');

        $processor = new OfflineProcessor();

        $this->assertFalse($processor->supportsMultipleCurrencies());
        $this->assertEquals('EUR', $processor->getCurrency());
    }

    /** @test */
    public function offline_processor_accepts_global_currency()
    {
        Config::set('payable.currency', 'EUR');

        $processor = new OfflineProcessor();
        $payable = new TestPayable(['amount' => 100.00]);
        $payable->save();
        $payer = new TestUser();
        $payer->save();

        // Should accept EUR (from global config)
        $payment1 = $processor->process($payable, $payer, 100.00);
        $this->assertEquals('EUR', $payment1->currency);

        // Should accept EUR when explicitly provided
        $payment2 = $processor->process($payable, $payer, 100.00, ['currency' => 'EUR']);
        $this->assertEquals('EUR', $payment2->currency);
    }

    /** @test */
    public function offline_processor_rejects_non_global_currency()
    {
        Config::set('payable.currency', 'EUR');

        $processor = new OfflineProcessor();
        $payable = new TestPayable(['amount' => 100.00]);
        $payable->save();
        $payer = new TestUser();
        $payer->save();

        // Should reject USD (different from global config)
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage("Processor 'offline' only supports currency 'EUR'");
        $this->expectExceptionMessage("Currency 'USD' is not supported");

        $processor->process($payable, $payer, 100.00, ['currency' => 'USD']);
    }

    /** @test */
    public function processor_uses_default_currency_when_none_provided()
    {
        // Mock Slickpay API configuration
        Config::set('payable.slickpay.api_key', 'test_key');
        Config::set('payable.slickpay.api_url', 'https://api.slickpay.test');

        $slickpayProcessor = new SlickpayProcessor();
        $payable = new TestPayable(['amount' => 100.00]);
        $payable->save();
        $payer = new TestUser();
        $payer->save();

        // Slickpay should use DZD when no currency provided
        // Note: Slickpay requires success_url for API calls, but currency validation happens before that
        $payment = $slickpayProcessor->process($payable, $payer, 100.00, [
            'success_url' => 'https://example.com/success'
        ]);
        $this->assertEquals('DZD', $payment->currency);

        Config::set('payable.currency', 'GBP');
        $offlineProcessor = new OfflineProcessor();
        $payable2 = new TestPayable(['amount' => 100.00]);
        $payable2->save();

        // Offline should use GBP (from global config) when no currency provided
        $payment2 = $offlineProcessor->process($payable2, $payer, 100.00);
        $this->assertEquals('GBP', $payment2->currency);
    }
}

