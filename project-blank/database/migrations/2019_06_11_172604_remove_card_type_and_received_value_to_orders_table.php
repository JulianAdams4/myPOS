<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RemoveCardTypeAndReceivedValueToOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('card_type');
            $table->dropColumn('received_value');
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
            $table->smallInteger('card_type')->default(0); // 0: No registrado, 1: Tarjeta de crédito, 2: Tarjeta de débito
            $table->integer('received_value')->nullable();
        });
    }
}
