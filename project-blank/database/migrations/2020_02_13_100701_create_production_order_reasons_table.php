<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProductionOrderReasonsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Types
        // 1: Cancelaciones no reversibles
        // 2: Cancelaciones reversibles
        // 3: Cancelación: Otros no reversible
        Schema::create('production_order_reasons', function (Blueprint $table) {
            $table->increments('id');
            $table->string('reason');
            $table->unsignedTinyInteger('type');
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
        Schema::dropIfExists('production_order_reasons');
    }
}
