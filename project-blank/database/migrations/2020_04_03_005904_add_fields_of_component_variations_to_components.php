<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddFieldsOfComponentVariationsToComponents extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('components', function (Blueprint $table) {
            $table->unsignedInteger('value')->nullable();
            $table->string('SKU')->nullable();

            $table->unsignedInteger('metric_unit_id')->nullable();
            $table->foreign('metric_unit_id')->references('id')->on('metric_units')->onDelete('cascade');
            $table->unsignedInteger('metric_unit_factor')->nullable();

            $table->unsignedInteger('conversion_metric_unit_id')->nullable();
            $table->foreign('conversion_metric_unit_id')->references('id')->on('metric_units')->onDelete('cascade');
            $table->unsignedInteger('conversion_metric_factor')->nullable();
        });

        Schema::table('component_stock', function (Blueprint $table) {
            $table->decimal('cost', 14, 4)->default(0);
            $table->unsignedInteger('merma')->nullable();
            $table->dropColumn('ideal_stock');
            $table->dropColumn('min_stock');
            $table->dropColumn('max_stock');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('components', function (Blueprint $table) {
            $table->dropForeign(['metric_unit_id']);
            $table->dropColumn('metric_unit_id');
            $table->dropForeign(['conversion_metric_unit_id']);
            $table->dropColumn('conversion_metric_unit_id');
            $table->dropColumn('value');
            $table->dropColumn('SKU');
            $table->dropColumn('conversion_metric_factor');
            $table->dropColumn('metric_unit_factor');
        });

        Schema::table('component_stock', function (Blueprint $table) {
            $table->dropColumn('cost');
            $table->dropColumn('merma');
            $table->float('min_stock', 8, 2)->nullable()->default(0);
            $table->float('max_stock', 8, 2)->nullable()->default(0);
            $table->float('ideal_stock', 8, 2)->nullable()->default(0);
        });
    }
}
