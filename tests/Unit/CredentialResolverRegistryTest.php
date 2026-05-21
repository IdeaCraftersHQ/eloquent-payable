<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

use Ideacrafters\EloquentPayable\Credentials\CredentialBundle;
use Ideacrafters\EloquentPayable\Credentials\CredentialResolver;
use Ideacrafters\EloquentPayable\Exceptions\InvalidCredentialBundleException;
use Ideacrafters\EloquentPayable\Facades\Payable;
use Ideacrafters\EloquentPayable\Models\Payment;
use Ideacrafters\EloquentPayable\Tests\TestCase;

class CredentialResolverRegistryTest extends TestCase
{
    /** @test */
    public function registering_a_resolver_makes_it_retrievable(): void
    {
        Payable::resolveCredentialsFor('satim', fn (Payment $payment) => [
            'username' => 'tenant-user',
            'password' => 'tenant-pass',
            'terminal_id' => 'T12345',
        ]);

        $resolver = Payable::getCredentialResolver('satim');

        $this->assertInstanceOf(CredentialResolver::class, $resolver);
    }

    /** @test */
    public function get_resolver_returns_null_when_none_registered(): void
    {
        // Note: no resolveCredentialsFor() call for 'slickpay' here.
        $this->assertNull(Payable::getCredentialResolver('slickpay'));
    }

    /** @test */
    public function re_registering_the_same_processor_replaces_the_resolver(): void
    {
        Payable::resolveCredentialsFor('satim', fn (Payment $payment) => ['username' => 'first']);
        Payable::resolveCredentialsFor('satim', fn (Payment $payment) => ['username' => 'second']);

        $resolver = Payable::getCredentialResolver('satim');
        $result = $resolver(new Payment);

        $this->assertSame('second', $result['username']);
    }

    /** @test */
    public function resolver_is_invoked_with_a_payment_and_returns_its_result(): void
    {
        Payable::resolveCredentialsFor('satim', function (Payment $payment) {
            return [
                'username' => 'resolved-user',
                'password' => 'resolved-pass',
                'terminal_id' => 'T99999',
                'payment_id' => $payment->id, // proves the Payment was passed through
            ];
        });

        $payment = new Payment;
        $payment->id = 42;

        $resolver = Payable::getCredentialResolver('satim');
        $result = $resolver($payment);

        $this->assertSame('resolved-user', $result['username']);
        $this->assertSame(42, $result['payment_id']);
    }

    /** @test */
    public function resolver_returning_null_is_propagated(): void
    {
        Payable::resolveCredentialsFor('satim', fn (Payment $payment) => null);

        $resolver = Payable::getCredentialResolver('satim');

        $this->assertNull($resolver(new Payment));
    }

    /** @test */
    public function satim_bundle_accepts_a_complete_credential_set(): void
    {
        $bundle = CredentialBundle::forSatim([
            'username' => 'sat-user',
            'password' => 'sat-pass',
            'terminal_id' => 'T1',
        ]);

        $this->assertSame('satim', $bundle->processor);
        $this->assertSame('sat-user', $bundle->get('username'));
        $this->assertSame('sat-pass', $bundle->get('password'));
        $this->assertSame('T1', $bundle->get('terminal_id'));
    }

    /** @test */
    public function satim_bundle_rejects_missing_username(): void
    {
        $this->expectException(InvalidCredentialBundleException::class);
        $this->expectExceptionMessageMatches('/username/');

        CredentialBundle::forSatim([
            'password' => 'sat-pass',
            'terminal_id' => 'T1',
        ]);
    }

    /** @test */
    public function satim_bundle_rejects_missing_password(): void
    {
        $this->expectException(InvalidCredentialBundleException::class);
        $this->expectExceptionMessageMatches('/password/');

        CredentialBundle::forSatim([
            'username' => 'sat-user',
            'terminal_id' => 'T1',
        ]);
    }

    /** @test */
    public function satim_bundle_rejects_missing_terminal_id(): void
    {
        $this->expectException(InvalidCredentialBundleException::class);
        $this->expectExceptionMessageMatches('/terminal_id/');

        CredentialBundle::forSatim([
            'username' => 'sat-user',
            'password' => 'sat-pass',
        ]);
    }

    /** @test */
    public function satim_bundle_rejects_empty_string_fields(): void
    {
        $this->expectException(InvalidCredentialBundleException::class);

        CredentialBundle::forSatim([
            'username' => '',
            'password' => 'sat-pass',
            'terminal_id' => 'T1',
        ]);
    }

    /** @test */
    public function slickpay_bundle_accepts_a_complete_credential_set(): void
    {
        $bundle = CredentialBundle::forSlickpay([
            'api_key' => 'slk-key',
            'sandbox_mode' => true,
        ]);

        $this->assertSame('slickpay', $bundle->processor);
        $this->assertSame('slk-key', $bundle->get('api_key'));
        $this->assertTrue($bundle->get('sandbox_mode'));
    }

    /** @test */
    public function slickpay_bundle_rejects_missing_api_key(): void
    {
        $this->expectException(InvalidCredentialBundleException::class);
        $this->expectExceptionMessageMatches('/api_key/');

        CredentialBundle::forSlickpay([
            'sandbox_mode' => true,
        ]);
    }

    /** @test */
    public function bundle_get_returns_default_when_key_absent(): void
    {
        $bundle = CredentialBundle::forSatim([
            'username' => 'sat-user',
            'password' => 'sat-pass',
            'terminal_id' => 'T1',
        ]);

        $this->assertSame('default-currency', $bundle->get('currency', 'default-currency'));
        $this->assertNull($bundle->get('language'));
    }

    /** @test */
    public function bundle_to_array_returns_full_credentials_map(): void
    {
        $bundle = CredentialBundle::forSatim([
            'username' => 'sat-user',
            'password' => 'sat-pass',
            'terminal_id' => 'T1',
            'language' => 'fr',
        ]);

        $this->assertSame(
            [
                'username' => 'sat-user',
                'password' => 'sat-pass',
                'terminal_id' => 'T1',
                'language' => 'fr',
            ],
            $bundle->toArray()
        );
    }
}
