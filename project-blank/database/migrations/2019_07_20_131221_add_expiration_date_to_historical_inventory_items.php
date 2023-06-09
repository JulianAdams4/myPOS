<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddExpirationDateToHistoricalInventoryItems extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('historical_inventory_items', function (Blueprint $table) {
            $table->date('expiration_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('historical_inventory_items', function (Blueprint $table) {
            $table->dropColumn('expiration_date');
        });
    }
}
