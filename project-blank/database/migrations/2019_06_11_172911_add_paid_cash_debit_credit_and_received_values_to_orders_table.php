<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPaidCashDebitCreditAndReceivedValuesToOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('value_cash')->nullable();
            $table->integer('value_debit_card')->nullable();
            $table->integer('value_credit_card')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('value_cash');
            $table->dropColumn('value_debit_card');
            $table->dropColumn('value_credit_card');
        });
    }
}
