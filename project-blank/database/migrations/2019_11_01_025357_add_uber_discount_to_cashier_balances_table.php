<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddUberDiscountToCashierBalancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('cashier_balances', function (Blueprint $table) {
            $table->unsignedInteger('uber_discount')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('cashier_balances', function (Blueprint $table) {
            $table->dropColumn('uber_discount');
            
        });
    }
}
