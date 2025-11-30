<?php

namespace Ideacrafters\EloquentPayable\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Ideacrafters\EloquentPayable\Contracts\Payable as PayableContract;
use Ideacrafters\EloquentPayable\Contracts\Payer as PayerContract;
use Ideacrafters\EloquentPayable\Traits\Payable;
use Ideacrafters\EloquentPayable\Traits\HasPayments;

/**
 * Shared test models for unit tests.
 */

/**
 * Test User model that implements Payer interface.
 * In real applications, User models typically implement Payer.
 */
class TestUser extends Model implements PayerContract
{
    use HasPayments;

    protected $table = 'test_payers';
    protected $fillable = ['id'];
    public $timestamps = true;

    public function canMakePayments(): bool
    {
        return true;
    }

    public function getEmail(): ?string
    {
        return 'test@example.com';
    }

    public function getFirstName(): ?string
    {
        return 'Test';
    }

    public function getLastName(): ?string
    {
        return 'User';
    }

    public function getBillingAddressAsString(): string
    {
        return '123 Test St, Test City, 12345';
    }
}

class TestInvoice extends Model implements PayableContract
{
    use Payable;

    protected $table = 'test_invoices';
    protected $fillable = ['amount'];

    public function getPayableAmount($payer = null): float
    {
        return $this->amount;
    }

    public function isPayableBy($payer): bool
    {
        return true;
    }

    public function isPayableActive(): bool
    {
        return true;
    }
}

class TestPayable extends Model implements PayableContract
{
    use Payable;

    protected $table = 'test_payables';
    protected $fillable = ['id', 'amount'];
    public $timestamps = false;

    public function getPayableAmount($payer = null): float
    {
        return $this->amount ?? 100.00;
    }

    public function isPayableBy($payer): bool
    {
        return true;
    }

    public function isPayableActive(): bool
    {
        return true;
    }
}

