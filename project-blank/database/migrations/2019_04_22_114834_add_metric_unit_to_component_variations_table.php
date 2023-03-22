<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddMetricUnitToComponentVariationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('component_variations', function (Blueprint $table) {
            $table->unsignedInteger('metric_unit_id')->nullable();
            $table->foreign('metric_unit_id')->references('id')->on('metric_units')->onDelete('cascade');
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
            $table->dropForeign(['metric_unit_id']);
            $table->dropColumn('metric_unit_id');
        });
    }
}
