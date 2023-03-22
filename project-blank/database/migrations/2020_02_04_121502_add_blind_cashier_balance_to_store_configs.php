<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddBlindCashierBalanceToStoreConfigs extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->boolean('blind_cashier_balance')->default(false);
        });
        Schema::table('cashier_balances', function (Blueprint $table) {
            $table->unsignedInteger('reported_value_close')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->dropColumn('blind_cashier_balance');
        });
        Schema::table('cashier_balances', function (Blueprint $table) {
            $table->dropColumn('reported_value_close');
        });
    }
}
