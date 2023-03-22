<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddSyncedIdToHistoricalInventory extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('historical_inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('synced_id')->nullable();
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
            $table->dropColumn('synced_id');
        });
    }
}
