<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProductCompatibilitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_compatibilities', function (Blueprint $table) {
            $table->increments('id');
            $table->string('description')->nullable();
            $table->unsignedInteger('product_id_origin');
            $table->unsignedInteger('product_id_compatible');
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->foreign('product_id_origin')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('product_id_compatible')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('product_compatibilities');
    }
}
