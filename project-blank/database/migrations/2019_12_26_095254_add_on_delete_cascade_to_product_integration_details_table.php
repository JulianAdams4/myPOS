<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOnDeleteCascadeToProductIntegrationDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('product_integration_details', function (Blueprint $table) {
            $table->dropForeign('product_integration_details_product_id_foreign');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('product_integration_details', function (Blueprint $table) {
            $table->dropForeign('product_integration_details_product_id_foreign');
            $table->foreign('product_id')->references('id')->on('products');
        });
    }
}
