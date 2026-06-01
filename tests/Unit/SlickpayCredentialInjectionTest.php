<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

require_once __DIR__.'/TestCaseHelpers.php';

use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Ideacrafters\EloquentPayable\Facades\Payable;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Processors\SlickpayProcessor;
use Ideacrafters\EloquentPayable\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class SlickpayCredentialInjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_payers', function ($table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('test_payables', function ($table) {
            $table->id();
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamps();
        });

        // Env-shaped Slickpay defaults — processor must read these when the
        // resolver returns null (or is not registered at all).
        Config::set('payable.slickpay.api_key', 'env-api-key');
        Config::set('payable.slickpay.sandbox_mode', true);
        Config::set('payable.slickpay.dev_api', 'https://devapi.slick-pay.test/api/v2');
        Config::set('payable.slickpay.prod_api', 'https://prodapi.slick-pay.test/api/v2');
        Config::set('payable.slickpay.fallbacks.email', 'customer@example.com');
    }

    /** @test */
    public function uses_resolved_credentials_when_resolver_is_registered(): void
    {
        $this->fakeSlickpayInvoiceOk();

        Payable::resolveCredentialsFor('slickpay', fn (Payment $p) => [
            'api_key' => 'tenant-api-key',
        ]);

        (new SlickpayProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            $this->baseOptions()
        );

        Http::assertSent(fn ($r) => str_contains($r->url(), '/users/invoices')
            && $r->hasHeader('Authorization', 'Bearer tenant-api-key')
        );
    }

    /** @test */
    public function falls_back_to_env_credentials_when_resolver_returns_null(): void
    {
        $this->fakeSlickpayInvoiceOk();

        Payable::resolveCredentialsFor('slickpay', fn (Payment $p) => null);

        (new SlickpayProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            $this->baseOptions()
        );

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer env-api-key'));
    }

    /** @test */
    public function falls_back_to_env_credentials_when_no_resolver_registered(): void
    {
        $this->fakeSlickpayInvoiceOk();

        (new SlickpayProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            $this->baseOptions()
        );

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer env-api-key'));
    }

    /** @test */
    public function uses_prod_base_url_when_resolver_sets_sandbox_mode_false(): void
    {
        Http::fake([
            'prodapi.slick-pay.test/*' => Http::response([
                'success' => true,
                'id' => 'invoice-123',
                'url' => 'https://prodapi.slick-pay.test/checkout/invoice-123',
            ], 200),
        ]);

        Payable::resolveCredentialsFor('slickpay', fn (Payment $p) => [
            'api_key' => 'tenant-api-key',
            'sandbox_mode' => false,
        ]);

        (new SlickpayProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            $this->baseOptions()
        );

        Http::assertSent(fn ($r) => str_contains($r->url(), 'prodapi.slick-pay.test'));
    }

    /** @test */
    public function throws_when_resolver_returns_partial_credentials(): void
    {
        Payable::resolveCredentialsFor('slickpay', fn (Payment $p) => [
            'sandbox_mode' => true,
            // missing api_key
        ]);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageMatches('/Credential bundle/i');

        (new SlickpayProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            $this->baseOptions()
        );
    }

    /** @test */
    public function persists_merchant_pointer_into_payment_metadata(): void
    {
        $this->fakeSlickpayInvoiceOk();

        $payment = (new SlickpayProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            array_merge($this->baseOptions(), [
                'merchant_pointer' => ['type' => 'App\\Models\\User', 'id' => 42],
            ])
        );

        $this->assertSame(
            ['type' => 'App\\Models\\User', 'id' => 42],
            $payment->fresh()->metadata['merchant_pointer']
        );
    }

    /** @test */
    public function resolver_receives_payment_with_merchant_pointer_already_in_metadata(): void
    {
        $this->fakeSlickpayInvoiceOk();

        $captured = null;
        Payable::resolveCredentialsFor('slickpay', function (Payment $p) use (&$captured) {
            $captured = $p->metadata['merchant_pointer'] ?? null;

            return ['api_key' => 'tenant-api-key'];
        });

        (new SlickpayProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            array_merge($this->baseOptions(), [
                'merchant_pointer' => ['type' => 'App\\Models\\User', 'id' => 99],
            ])
        );

        $this->assertSame(['type' => 'App\\Models\\User', 'id' => 99], $captured);
    }

    private function fakeSlickpayInvoiceOk(): void
    {
        Http::fake([
            '*/users/invoices' => Http::response([
                'success' => true,
                'id' => 'invoice-123',
                'url' => 'https://devapi.slick-pay.test/checkout/invoice-123',
            ], 200),
        ]);
    }

    private function makePayable(): TestPayable
    {
        $payable = new TestPayable(['amount' => 100.00]);
        $payable->save();

        return $payable;
    }

    private function makePayer(): TestUser
    {
        $payer = new TestUser;
        $payer->save();

        return $payer;
    }

    /**
     * Slickpay createInvoice needs a success URL on every call.
     */
    private function baseOptions(): array
    {
        return [
            'success_url' => 'https://app.example.test/payments/success',
        ];
    }
}
