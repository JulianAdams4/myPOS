<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrderProductSpecificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('order_product_specifications', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_detail_id');
            $table->unsignedInteger('specification_id');
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->foreign('order_detail_id')->references('id')->on('order_details')->onDelete('cascade');
            $table->foreign('specification_id')->references('id')->on('specifications')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_product_specifications');
    }
}
