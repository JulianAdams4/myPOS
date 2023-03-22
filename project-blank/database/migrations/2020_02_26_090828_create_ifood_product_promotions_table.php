<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateIfoodProductPromotionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ifood_product_promotions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_integration_id');
            $table->foreign('product_integration_id')->references('id')
                ->on('product_integration_details')->onDelete('cascade');
            $table->decimal('value', 14, 4);
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
        Schema::dropIfExists('ifood_product_promotions');
    }
}
