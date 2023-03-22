<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddConversionMetricUnitIdOnComponentVariationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('component_variations', function (Blueprint $table) {
            $table->unsignedInteger('conversion_metric_unit_id')->nullable();
            $table->foreign('conversion_metric_unit_id')->references('id')->on('metric_units')->onDelete('cascade');
        });
        
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('component_variations', function (Blueprint $table) {
            $table->dropForeign(['conversion_metric_unit_id']);
            $table->dropColumn('conversion_metric_unit_id');
        });
    }
}
