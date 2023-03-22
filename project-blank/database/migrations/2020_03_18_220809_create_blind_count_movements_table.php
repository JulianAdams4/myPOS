<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBlindCountMovementsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('blind_count_movements', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();
            $table->unsignedInteger('blind_count_id');
            $table->unsignedInteger('stock_movement_id');
            $table->decimal('value', 14, 4);
            $table->decimal('cost', 14, 4);

            $table->foreign('blind_count_id')->references('id')->on('blind_counts')->onDelete('cascade');
            $table->foreign('stock_movement_id')->references('id')->on('stock_movements')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('blind_count_movements');
    }
}
