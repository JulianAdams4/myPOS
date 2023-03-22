<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUnitConversionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('unit_conversions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('unit_origin_id');
            $table->foreign('unit_origin_id')->references('id')->on('metric_units')->onDelete('cascade');
            $table->unsignedInteger('unit_destination_id');
            $table->foreign('unit_destination_id')->references('id')->on('metric_units')->onDelete('cascade');
            $table->decimal('multiplier', 20, 10)->nullable();
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
        Schema::dropIfExists('unit_conversions');
    }
}
