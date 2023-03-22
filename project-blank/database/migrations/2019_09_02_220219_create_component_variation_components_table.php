<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateComponentVariationComponentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('component_variation_components', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('comp_var_origin_id')->unsigned();
            $table->foreign('comp_var_origin_id')->references('id')
                ->on('component_variations')->onDelete('cascade');
            $table->integer('comp_var_destination_id')->unsigned();
            $table->foreign('comp_var_destination_id')->references('id')
                ->on('component_variations')->onDelete('cascade');
            $table->float('value_reference', 8, 2);
            $table->float('consumption', 8, 2);
            $table->softDeletes();
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
        Schema::dropIfExists('component_variation_components');
    }
}
