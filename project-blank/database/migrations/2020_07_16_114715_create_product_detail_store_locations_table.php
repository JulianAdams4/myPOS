<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\ProductDetail;
use App\ProductDetailStoreLocation;

class CreateProductDetailStoreLocationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_detail_store_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_detail_id');
            $table->unsignedInteger('store_location_id');
            $table->foreign('product_detail_id')->references('id')->on('product_details')->onDelete('cascade');
            $table->foreign('store_location_id')->references('id')->on('store_locations')->onDelete('cascade');
            $table->timestamps();
        });

        $productDetails = ProductDetail::whereNotNull('location_id')->get();
        foreach ($productDetails as $productDetail) {
            ProductDetailStoreLocation::create([
                'product_detail_id' => $productDetail->id,
                'store_location_id' => $productDetail->location_id
            ]);
        }

        Schema::table('product_details', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('product_detail_store_locations');
        Schema::table('product_details', function (Blueprint $table) {
            $table->unsignedInteger('location_id')->nullable();
            $table->foreign('location_id')->references('id')->on('store_locations')->onDelete('set null');
        });
    }
}
