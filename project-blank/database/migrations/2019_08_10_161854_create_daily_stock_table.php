<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateDailyStockTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('daily_stock', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('component_stock_id')->nullable();
            $table->foreign('component_stock_id')
                ->references('id')->on('component_stock')->onDelete('cascade');
            $table->unsignedTinyInteger('day');
            $table->float('min_stock', 8, 2)->default(0);
            $table->float('max_stock', 8, 2);
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
        Schema::dropIfExists('daily_stock');
    }
}
