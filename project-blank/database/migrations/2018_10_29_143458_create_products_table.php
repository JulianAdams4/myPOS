<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_category_id');
            $table->string('name');
            $table->string('search_string');
            $table->string('description')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->unsignedInteger('base_value');
            $table->enum('type',['main','addon']);
            $table->text('image')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->foreign('product_category_id')->references('id')->on('product_categories')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('products');
    }
}
