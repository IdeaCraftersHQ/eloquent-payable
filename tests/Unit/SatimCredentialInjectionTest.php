<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

require_once __DIR__.'/TestCaseHelpers.php';

use Ideacrafters\EloquentPayable\Exceptions\PaymentException;
use Ideacrafters\EloquentPayable\Facades\Payable;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Processors\SatimProcessor;
use Ideacrafters\EloquentPayable\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class SatimCredentialInjectionTest extends TestCase
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

        // Env-shaped SATIM defaults — the processor must read these when the
        // resolver returns null (or is not registered at all).
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
    public function uses_resolved_credentials_when_resolver_is_registered(): void
    {
        $this->fakeSatimRegisterOk();

        Payable::resolveCredentialsFor('satim', fn (Payment $p) => [
            'username' => 'tenant-user',
            'password' => 'tenant-pass',
            'terminal_id' => 'TENANTT',
        ]);

        (new SatimProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            $this->baseOptions()
        );

        Http::assertSent(fn ($r) => str_contains($r->url(), '/register.do')
            && $r['userName'] === 'tenant-user'
            && $r['password'] === 'tenant-pass'
        );
    }

    /** @test */
    public function falls_back_to_env_credentials_when_resolver_returns_null(): void
    {
        $this->fakeSatimRegisterOk();

        Payable::resolveCredentialsFor('satim', fn (Payment $p) => null);

        (new SatimProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            $this->baseOptions()
        );

        Http::assertSent(fn ($r) => $r['userName'] === 'env-user' && $r['password'] === 'env-pass');
    }

    /** @test */
    public function falls_back_to_env_credentials_when_no_resolver_registered(): void
    {
        $this->fakeSatimRegisterOk();

        (new SatimProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            $this->baseOptions()
        );

        Http::assertSent(fn ($r) => $r['userName'] === 'env-user' && $r['password'] === 'env-pass');
    }

    /** @test */
    public function uses_resolved_terminal_id_in_satim_request(): void
    {
        $this->fakeSatimRegisterOk();

        Payable::resolveCredentialsFor('satim', fn (Payment $p) => [
            'username' => 'tenant-user',
            'password' => 'tenant-pass',
            'terminal_id' => 'TENANTXYZ',
        ]);

        (new SatimProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            $this->baseOptions()
        );

        Http::assertSent(function ($r) {
            $params = json_decode($r['jsonParams'] ?? '{}', true);

            return ($params['force_terminal_id'] ?? null) === 'TENANTXYZ';
        });
    }

    /** @test */
    public function throws_when_resolver_returns_partial_credentials(): void
    {
        Payable::resolveCredentialsFor('satim', fn (Payment $p) => [
            'username' => 'partial-user',
            // missing password + terminal_id
        ]);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageMatches('/Credential bundle/i');

        (new SatimProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            $this->baseOptions()
        );
    }

    /** @test */
    public function persists_merchant_pointer_into_payment_metadata(): void
    {
        $this->fakeSatimRegisterOk();

        $payment = (new SatimProcessor())->process(
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
        $this->fakeSatimRegisterOk();

        $captured = null;
        Payable::resolveCredentialsFor('satim', function (Payment $p) use (&$captured) {
            $captured = $p->metadata['merchant_pointer'] ?? null;

            return [
                'username' => 'tenant-user',
                'password' => 'tenant-pass',
                'terminal_id' => 'TENANTT',
            ];
        });

        (new SatimProcessor())->process(
            $this->makePayable(),
            $this->makePayer(),
            100.00,
            array_merge($this->baseOptions(), [
                'merchant_pointer' => ['type' => 'App\\Models\\User', 'id' => 99],
            ])
        );

        $this->assertSame(['type' => 'App\\Models\\User', 'id' => 99], $captured);
    }

    private function fakeSatimRegisterOk(): void
    {
        Http::fake([
            '*register.do*' => Http::response([
                'orderId' => 'fake-order-123',
                'formUrl' => 'https://satim.test/pay/fake-order-123',
                'errorCode' => '0',
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
     * SATIM register requires orderNumber + udf1 — without them the SDK
     * throws SatimValidationException before the HTTP call, which would
     * mask the credential-injection path we're trying to test.
     */
    private function baseOptions(): array
    {
        return [
            'order_number' => '1234567890',
            'udf1' => 'testudf1',
        ];
    }
}
