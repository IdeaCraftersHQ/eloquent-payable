<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

require_once __DIR__.'/TestCaseHelpers.php';

use Ideacrafters\EloquentPayable\Facades\Payable;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\PaymentStatus;
use Ideacrafters\EloquentPayable\Processors\ProcessorNames;
use Ideacrafters\EloquentPayable\Processors\SlickpayProcessor;
use Ideacrafters\EloquentPayable\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class SlickpayMerchantPointerTest extends TestCase
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

        Config::set('payable.slickpay.api_key', 'env-api-key');
        Config::set('payable.slickpay.sandbox_mode', true);
        Config::set('payable.slickpay.dev_api', 'https://devapi.slick-pay.test/api/v2');
        Config::set('payable.slickpay.prod_api', 'https://prodapi.slick-pay.test/api/v2');
    }

    /** @test */
    public function complete_redirect_re_resolves_credentials_using_stored_merchant_pointer(): void
    {
        Http::fake([
            '*/users/invoices/*' => Http::response([
                'data' => ['completed' => true, 'status' => 'paid'],
            ], 200),
        ]);

        $calls = 0;
        Payable::resolveCredentialsFor('slickpay', function (Payment $p) use (&$calls) {
            $calls++;
            $pointer = $p->metadata['merchant_pointer'] ?? null;
            if (($pointer['id'] ?? null) === 99) {
                return ['api_key' => 'tenant-api-key'];
            }

            return null;
        });

        $payable = new TestPayable(['amount' => 100.00]);
        $payable->save();
        $payer = new TestUser;
        $payer->save();

        $payment = Payment::create([
            'payer_type' => $payer->getMorphClass(),
            'payer_id' => $payer->getKey(),
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'amount' => 100.00,
            'currency' => 'DZD',
            'status' => PaymentStatus::processing(),
            'processor' => ProcessorNames::SLICKPAY,
            'reference' => 'existing-invoice-id',
            'metadata' => [
                'merchant_pointer' => ['type' => 'App\\Models\\User', 'id' => 99],
            ],
        ]);

        (new SlickpayProcessor())->completeRedirect($payment);

        $this->assertSame(1, $calls, 'Resolver should be invoked once at confirm time.');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/users/invoices/existing-invoice-id')
            && $r->hasHeader('Authorization', 'Bearer tenant-api-key')
        );
    }
}
