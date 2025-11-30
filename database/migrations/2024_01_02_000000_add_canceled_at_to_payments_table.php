<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCanceledAtToPaymentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $tableName = config('payable.tables.payments', 'payments');

        Schema::table($tableName, function (Blueprint $table) {
            $table->timestamp('canceled_at')->nullable()->after('failed_at');
            $table->index('canceled_at', 'idx_canceled_at');
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

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropIndex('idx_canceled_at');
            $table->dropColumn('canceled_at');
        });
    }
}

