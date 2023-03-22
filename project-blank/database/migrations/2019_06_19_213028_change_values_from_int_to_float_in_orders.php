<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeValuesFromIntToFloatInOrders extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->float('order_value')->change();
            $table->float('total')->change();
            $table->float('base_value')->change();
            $table->float('value_cash')->change();
            $table->float('value_debit_card')->change();
            $table->float('value_credit_card')->change();
            $table->float('discount_percentage')->change();
            $table->float('discount_value')->change();
            $table->float('undiscounted_base_value')->change();
            $table->float('change_value')->change();
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
            //
        });
    }
}
