<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpecificationComponentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('specification_components', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('component_variation_id')->unsigned(); 
            $table->foreign('component_variation_id')->references('id')->on('component_variations')->onDelete('cascade');
            $table->unsignedInteger('specification_id');
            $table->foreign('specification_id')->references('id')->on('specifications')->onDelete('cascade');
            $table->boolean('status')->default(true);    
            $table->float('consumption', 8, 2)->nullable();
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
        Schema::dropIfExists('specification_components');
    }
}
