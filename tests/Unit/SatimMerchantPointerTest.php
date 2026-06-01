<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

require_once __DIR__.'/TestCaseHelpers.php';

use Ideacrafters\EloquentPayable\Facades\Payable;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\PaymentStatus;
use Ideacrafters\EloquentPayable\Processors\ProcessorNames;
use Ideacrafters\EloquentPayable\Processors\SatimProcessor;
use Ideacrafters\EloquentPayable\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class SatimMerchantPointerTest extends TestCase
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

        Config::set('satim.username', 'env-user');
        Config::set('satim.password', 'env-pass');
        Config::set('satim.terminal_id', 'ENVT1');
        Config::set('satim.api_url', 'https://test2.satim.dz/payment/rest');
        Config::set('satim.language', 'fr');
        Config::set('satim.currency', '012');
        Config::set('satim.verify_ssl', true);
        Config::set('satim.timeout', 30);
        Config::set('satim.connect_timeout', 10);
    }

    /** @test */
    public function complete_redirect_re_resolves_credentials_using_stored_merchant_pointer(): void
    {
        Http::fake([
            '*acknowledgeTransaction.do*' => Http::response([
                'orderStatus' => 2, // 2 = approved/authorized
                'orderNumber' => 'ON-1',
                'ErrorCode' => '0',
                'actionCode' => 0,
                'amount' => 10000,
                'currency' => '012',
                'date' => 0,
                'depositedDate' => 0,
                'authDateTime' => 0,
                'terminalId' => 'TENANTXYZ',
                'authRefNum' => 'REF',
                'paymentAmountInfo' => [],
                'bankInfo' => [],
                'params' => ['respCode' => '00', 'respCode_desc' => 'Approved'],
            ], 200),
        ]);

        // Resolver returns tenant credentials whenever it sees this merchant pointer.
        $calls = 0;
        Payable::resolveCredentialsFor('satim', function (Payment $p) use (&$calls) {
            $calls++;
            $pointer = $p->metadata['merchant_pointer'] ?? null;
            if (($pointer['id'] ?? null) === 99) {
                return [
                    'username' => 'tenant-user',
                    'password' => 'tenant-pass',
                    'terminal_id' => 'TENANTXYZ',
                ];
            }

            return null;
        });

        // Simulate a payment created earlier by the create flow, carrying a merchant pointer.
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
            'processor' => ProcessorNames::SATIM,
            'reference' => 'existing-order-id',
            'metadata' => [
                'merchant_pointer' => ['type' => 'App\\Models\\User', 'id' => 99],
            ],
        ]);

        (new SatimProcessor())->completeRedirect($payment);

        $this->assertSame(1, $calls, 'Resolver should be invoked once at confirm time.');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/acknowledgeTransaction.do')
            && $r['userName'] === 'tenant-user'
            && $r['password'] === 'tenant-pass'
        );
    }

    /** @test */
    public function refund_re_resolves_credentials_using_stored_merchant_pointer(): void
    {
        Http::fake([
            '*refund.do*' => Http::response([
                'errorCode' => '0',
            ], 200),
        ]);

        Payable::resolveCredentialsFor('satim', function (Payment $p) {
            $pointer = $p->metadata['merchant_pointer'] ?? null;
            if (($pointer['id'] ?? null) === 99) {
                return [
                    'username' => 'tenant-user',
                    'password' => 'tenant-pass',
                    'terminal_id' => 'TENANTXYZ',
                ];
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
            'status' => PaymentStatus::completed(),
            'processor' => ProcessorNames::SATIM,
            'reference' => 'existing-order-id',
            'paid_at' => now(),
            'metadata' => [
                'merchant_pointer' => ['type' => 'App\\Models\\User', 'id' => 99],
            ],
        ]);

        $refunded = (new SatimProcessor())->refund($payment, 100.00);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/refund.do')
            && $r['userName'] === 'tenant-user'
            && $r['password'] === 'tenant-pass'
        );

        // Refund went through and the payment was marked refunded.
        $this->assertSame((string) PaymentStatus::refunded(), (string) $refunded->fresh()->status);
        $this->assertEquals(100.00, (float) $refunded->fresh()->refunded_amount);
    }
}
