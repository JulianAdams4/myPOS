<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateHistoricalInventoryItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('historical_inventory_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('component_variation_id');
            $table->foreign('component_variation_id')->references('id')->on('component_variations')->onDelete('cascade');
            $table->unsignedInteger('inventory_action_id');
            $table->foreign('inventory_action_id')->references('id')->on('inventory_actions')->onDelete('cascade');
            $table->float('stock', 8, 2)->default(0);
            $table->float('consumption', 8, 2)->default(0);
            $table->unsignedInteger('cost')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('historical_inventory_items');
    }
}
