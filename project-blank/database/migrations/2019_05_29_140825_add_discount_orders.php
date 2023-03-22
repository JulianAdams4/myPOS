<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddDiscountOrders extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('discount_percentage')->nullable();
            $table->integer('discount_value')->nullable();
            $table->integer('undiscounted_base_value')->nullable();
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
            $table->dropcolumn('discount_percentage');
            $table->dropcolumn('discount_value');
            $table->dropcolumn('undiscounted_base_value');
        });
    }
}
