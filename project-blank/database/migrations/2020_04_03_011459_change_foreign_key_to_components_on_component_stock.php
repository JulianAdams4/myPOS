<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeForeignKeyToComponentsOnComponentStock extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('component_stock', function (Blueprint $table) {
            $table->dropForeign(['component_variation_id']);
            $table->unsignedInteger('component_variation_id')->nullable()->change();
        });

        Schema::table('product_components', function (Blueprint $table) {
            $table->dropForeign(['component_variation_id']);
            $table->unsignedInteger('component_variation_id')->nullable()->change();
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropForeign(['component_variation_id']);
            $table->unsignedInteger('component_variation_id')->nullable()->change();
        });

        Schema::table('product_specification_components', function (Blueprint $table) {
            $table->dropForeign(['component_variation_id']);
            $table->unsignedInteger('component_variation_id')->nullable()->change();
        });

        Schema::table('specification_components', function (Blueprint $table) {
            $table->dropForeign(['component_variation_id']);
            $table->unsignedInteger('component_variation_id')->nullable()->change();
        });

        Schema::table('component_variation_components', function (Blueprint $table) {
            $table->dropForeign(['comp_var_origin_id']);
            $table->unsignedInteger('comp_var_origin_id')->nullable()->change();
            $table->dropForeign(['comp_var_destination_id']);
            $table->unsignedInteger('comp_var_destination_id')->nullable()->change();
        });

        Schema::table('invoice_provider_details', function (Blueprint $table) {
            $table->dropForeign(['component_variation_id']);
            $table->unsignedInteger('component_variation_id')->nullable()->change(); 
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
            $table->unsignedInteger('component_variation_id')->nullable(false)->change();
            $table->foreign('component_variation_id')->references('id')->on('component_variations')->onDelete('cascade');
        });

        Schema::table('product_components', function (Blueprint $table) {
            $table->foreign('component_variation_id')->references('id')->on('component_variations')->onDelete('cascade');
            $table->unsignedInteger('component_variation_id')->nullable(false)->change();
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->foreign('component_variation_id')->references('id')->on('component_variations')->onDelete('cascade');
            $table->unsignedInteger('component_variation_id')->nullable(false)->change();
        });

        Schema::table('product_specification_components', function (Blueprint $table) {
            $table->foreign('component_variation_id')->references('id')->on('component_variations')->onDelete('cascade');
            $table->unsignedInteger('component_variation_id')->nullable(false)->change();
        });

        Schema::table('specification_components', function (Blueprint $table) {
            $table->foreign('component_variation_id')->references('id')->on('component_variations')->onDelete('cascade');
            $table->unsignedInteger('component_variation_id')->nullable(false)->change();
        });

        Schema::table('product_specification_components', function (Blueprint $table) {
            $table->foreign('component_variation_id')->references('id')->on('component_variations')->onDelete('cascade');
            $table->unsignedInteger('component_variation_id')->nullable(false)->change();
        });

        Schema::table('component_variation_components', function (Blueprint $table) {
            $table->foreign('comp_var_origin_id')->references('id')->on('component_variations')->onDelete('cascade');
            $table->unsignedInteger('comp_var_origin_id')->nullable(false)->change();
            $table->foreign('comp_var_destination_id')->references('id')->on('component_variations')->onDelete('cascade');
            $table->unsignedInteger('comp_var_destination_id')->nullable(false)->change();
        });

        Schema::table('invoice_provider_details', function (Blueprint $table) {
            $table->foreign('component_variation_id')->references('id')->on('component_variations')->onDelete('cascade');
            $table->unsignedInteger('component_variation_id')->nullable(false)->change();
        });
    }
}
