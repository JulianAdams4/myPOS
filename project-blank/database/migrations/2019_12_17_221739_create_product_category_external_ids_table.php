<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProductCategoryExternalIdsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_category_external_ids', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_category_id');
            $table->foreign('product_category_id')->references('id')->on('product_categories')->onDelete('cascade');
            $table->integer('integration_id')->unsigned();
            $table->foreign('integration_id')->references('id')
                ->on('available_mypos_integrations')->onDelete('cascade');
            $table->string("external_id");
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
        Schema::dropIfExists('product_category_external_ids');
    }
}
