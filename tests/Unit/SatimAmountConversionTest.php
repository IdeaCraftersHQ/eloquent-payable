<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

require_once __DIR__.'/TestCaseHelpers.php';

use Ideacrafters\EloquentPayable\Exceptions\PaymentAmountMismatchException;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\PaymentStatus;
use Ideacrafters\EloquentPayable\Processors\SatimProcessor;
use Ideacrafters\EloquentPayable\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression coverage for the double centime conversion reported in #8.
 *
 * satim-laravel's Satim::amount() and Satim::refund() take dinars and convert
 * to centimes themselves, so the processor must pass major units. Converting
 * on this side too sent SATIM 100x the intended amount.
 */
class SatimAmountConversionTest extends TestCase
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

    #[Test]
    public function register_sends_the_amount_in_centimes_exactly_once(): void
    {
        $this->fakeSatimRegisterOk();

        (new SatimProcessor)->process(
            $this->makePayable(2500.00),
            $this->makePayer(),
            2500.00,
            $this->baseOptions()
        );

        // 2 500,00 DZD is 250 000 centimes — not 25 000 000.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/register.do')
            && (int) $r['amount'] === 250000
        );
    }

    #[Test]
    public function register_sends_a_whole_dinar_amount_without_rescaling_it(): void
    {
        $this->fakeSatimRegisterOk();

        (new SatimProcessor)->process(
            $this->makePayable(1234.00),
            $this->makePayer(),
            1234.00,
            $this->baseOptions()
        );

        Http::assertSent(fn ($r) => (int) $r['amount'] === 123400);
    }

    #[Test]
    public function refund_sends_the_amount_in_centimes_exactly_once(): void
    {
        Http::fake([
            '*refund.do*' => Http::response(['errorCode' => '0'], 200),
        ]);

        $payment = $this->makeCompletedPayment(2500.00);

        (new SatimProcessor)->refund($payment, 2500.00);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/refund.do')
            && (int) $r['amount'] === 250000
        );
    }

    #[Test]
    public function completing_a_redirect_accepts_a_matching_amount(): void
    {
        $this->fakeSatimConfirm(orderStatus: 2, depositAmount: 250000);

        $payment = $this->makeProcessingPayment(2500.00);

        $completed = (new SatimProcessor)->completeRedirect($payment);

        $this->assertSame(PaymentStatus::completed(), $completed->fresh()->status);
    }

    #[Test]
    public function completing_a_redirect_rejects_a_mismatched_amount(): void
    {
        // What a double-converted registration would come back as.
        $this->fakeSatimConfirm(orderStatus: 2, depositAmount: 25000000);

        $payment = $this->makeProcessingPayment(2500.00);

        try {
            (new SatimProcessor)->completeRedirect($payment);
            $this->fail('Expected PaymentAmountMismatchException.');
        } catch (PaymentAmountMismatchException $e) {
            $this->assertStringContainsString('25000000', $e->getMessage());
            $this->assertStringContainsString('250000', $e->getMessage());
        }

        $payment = $payment->fresh();

        $this->assertSame(PaymentStatus::failed(), $payment->status);
        $this->assertSame(
            ['expected_centimes' => 250000, 'charged_centimes' => 25000000],
            $payment->metadata['amount_mismatch']
        );
    }

    #[Test]
    public function completing_a_redirect_skips_verification_when_satim_reports_no_amount(): void
    {
        $this->fakeSatimConfirm(orderStatus: 2, depositAmount: null);

        $payment = $this->makeProcessingPayment(2500.00);

        $completed = (new SatimProcessor)->completeRedirect($payment);

        $this->assertSame(PaymentStatus::completed(), $completed->fresh()->status);
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

    private function fakeSatimConfirm(int $orderStatus, ?int $depositAmount): void
    {
        $body = [
            'errorCode' => '0',
            'OrderStatus' => $orderStatus,
            'orderStatus' => $orderStatus,
            'orderNumber' => '1234567890',
            'approvalCode' => '123456',
            'params' => ['respCode' => '00', 'respCode_desc' => 'Approved'],
        ];

        if ($depositAmount !== null) {
            $body['depositAmount'] = $depositAmount;
            $body['Amount'] = $depositAmount;
        }

        Http::fake([
            '*acknowledgeTransaction.do*' => Http::response($body, 200),
        ]);
    }

    private function makeProcessingPayment(float $amount): Payment
    {
        $payable = $this->makePayable($amount);
        $payer = $this->makePayer();

        return Payment::create([
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'payer_type' => $payer->getMorphClass(),
            'payer_id' => $payer->getKey(),
            'processor' => 'satim',
            'amount' => $amount,
            'currency' => 'DZD',
            'status' => PaymentStatus::processing(),
            'reference' => 'fake-order-123',
        ]);
    }

    private function makeCompletedPayment(float $amount): Payment
    {
        $payment = $this->makeProcessingPayment($amount);
        $payment->update(['status' => PaymentStatus::completed()]);

        return $payment->fresh();
    }

    private function makePayable(float $amount = 100.00): TestPayable
    {
        $payable = new TestPayable(['amount' => $amount]);
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
     * SATIM register requires orderNumber + udf1 — without them the SDK throws
     * SatimValidationException before the HTTP call is made.
     */
    private function baseOptions(): array
    {
        return [
            'order_number' => '1234567890',
            'udf1' => 'testudf1',
        ];
    }
}
