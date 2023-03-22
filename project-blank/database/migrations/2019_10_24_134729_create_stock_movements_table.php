<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateStockMovementsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('stock_movements', function (Blueprint $table) {
			$table->increments('id');
			$table->unsignedInteger('inventory_action_id');
			$table->foreign('inventory_action_id')->references('id')->on('inventory_actions')->onDelete('cascade');
			$table->float('initial_stock', 8, 2)->default(0);
			$table->float('value', 8, 2)->default(0);
			$table->float('final_stock', 8, 2)->default(0);
			$table->float('cost', 8, 2)->default(0);
			$table->unsignedInteger('component_stock_id');
			$table->foreign('component_stock_id')->references('id')->on('component_stock')->onDelete('cascade');
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
        Schema::dropIfExists('stock_movements');
    }
}
