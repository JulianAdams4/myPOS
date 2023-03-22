<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeFloatPrecisionInOrders extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('order_value', 14, 4)->change();
            $table->decimal('total', 14, 4)->change();
            $table->decimal('base_value', 14, 4)->change();
            $table->decimal('value_cash', 14, 4)->change();
            $table->decimal('value_debit_card', 14, 4)->change();
            $table->decimal('value_credit_card', 14, 4)->change();
            $table->decimal('discount_percentage', 5, 2)->change();
            $table->decimal('discount_value', 14, 4)->change();
            $table->decimal('undiscounted_base_value', 14, 4)->change();
            $table->decimal('change_value', 14, 4)->change();
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
