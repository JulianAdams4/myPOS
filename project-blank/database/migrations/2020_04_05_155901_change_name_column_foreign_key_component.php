<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeNameColumnForeignKeyComponent extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('component_stock', function (Blueprint $table) {
            $table->renameColumn('component_variation_id', 'component_id');
        });

        Schema::table('product_components', function (Blueprint $table) {
            $table->renameColumn('component_variation_id', 'component_id');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->renameColumn('component_variation_id', 'component_id');
        });

        Schema::table('product_specification_components', function (Blueprint $table) {
            $table->renameColumn('component_variation_id', 'component_id');
        });

        Schema::table('specification_components', function (Blueprint $table) {
            $table->renameColumn('component_variation_id', 'component_id');
        });

        Schema::table('component_variation_components', function (Blueprint $table) {
            $table->renameColumn('comp_var_origin_id', 'component_origin_id');
            $table->renameColumn('comp_var_destination_id', 'component_destination_id');
        });

        Schema::table('invoice_provider_details', function (Blueprint $table) {
            $table->renameColumn('component_variation_id', 'component_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('component_stock', function (Blueprint $table) {
            $table->renameColumn('component_id', 'component_variation_id');
        });

        Schema::table('product_components', function (Blueprint $table) {
            $table->renameColumn('component_id', 'component_variation_id');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->renameColumn('component_id', 'component_variation_id');
        });

        Schema::table('product_specification_components', function (Blueprint $table) {
            $table->renameColumn('component_id', 'component_variation_id');
        });

        Schema::table('specification_components', function (Blueprint $table) {
            $table->renameColumn('component_id', 'component_variation_id');
        });

        Schema::table('component_variation_components', function (Blueprint $table) {
            $table->renameColumn('component_origin_id', 'comp_var_origin_id');
            $table->renameColumn('component_destination_id', 'comp_var_destination_id');
        });

        Schema::table('invoice_provider_details', function (Blueprint $table) {
            $table->renameColumn('component_variation_id', 'component_id');
        });
    }
}
