<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeForeignComponentToProductComponentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('product_components', function (Blueprint $table) {
            $table->dropForeign('product_components_component_id_foreign');
            $table->dropColumn("component_id");
            $table->integer('component_variation_id')->unsigned();
            $table->foreign('component_variation_id')->references('id')->on('component_variations')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('product_components', function (Blueprint $table) {
            $table->unsignedInteger('component_id')->nullable();
            $table->foreign('component_id')->references('id')->on('components')->onDelete('cascade');
            $table->dropForeign(['component_variation_id']);
            $table->dropColumn("component_variation_id");
        });
    }
}
