<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeConsumptionPrecision extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('specification_components', function (Blueprint $table) {
            $table->decimal('consumption', 14, 4)->change();
        });

        Schema::table('product_specification_components', function (Blueprint $table) {
            $table->decimal('consumption', 14, 4)->change();
        });

        Schema::table('components', function (Blueprint $table) {
            $table->decimal('metric_unit_factor', 14, 4)->change();
            $table->decimal('conversion_metric_factor', 14, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('specification_components', function (Blueprint $table) {
            $table->float('consumption', 8, 2)->change();
        });

        Schema::table('product_specification_components', function (Blueprint $table) {
            $table->float('consumption', 8, 2)->change();
        });

        Schema::table('components', function (Blueprint $table) {
            $table->unsignedInteger('metric_unit_factor')->change();
            $table->unsignedInteger('conversion_metric_factor')->change();
        });
    }
}
