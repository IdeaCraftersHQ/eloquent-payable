<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePayableTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $tableName = config('payable.tables.payments', 'payments');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->morphs('payer');
            $table->morphs('payable');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status', 50)->default('pending');
            $table->string('processor', 50);
            $table->string('reference')->nullable();
            $table->json('metadata')->nullable();
            $table->decimal('refunded_amount', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // Indexes for better performance
            $table->index(['payer_type', 'payer_id'], 'idx_payer');
            $table->index(['payable_type', 'payable_id'], 'idx_payable');
            $table->index('status', 'idx_status');
            $table->index('processor', 'idx_processor');
            $table->index('reference', 'idx_reference');
            $table->index('paid_at', 'idx_paid_at');
            $table->index('failed_at', 'idx_failed_at');
            $table->index('created_at', 'idx_created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $tableName = config('payable.tables.payments', 'payments');
        Schema::dropIfExists($tableName);
    }
}
