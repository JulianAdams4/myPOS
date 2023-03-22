<?php

use App\ComponentVariation;
use App\ComponentStock;
use App\HistoricalInventoryItem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateComponentStockTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $oldData = ComponentVariation::select('id', 'store_id', 'stock', 'alert_stock')->get();
        Schema::create('component_stock', function (Blueprint $table) {
            $table->increments('id');
            
            $table->timestamps();
            $table->float('stock', 8, 2)->default(0);
            $table->unsignedInteger('alert_stock')->default(1)->nullable();
            $table->unsignedInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->unsignedInteger('component_variation_id');
            $table->foreign('component_variation_id')
                    ->references('id')->on('component_variations')->onDelete('cascade');
        });

        foreach ($oldData as $variation) {
            $lastRecordInventoryItem = HistoricalInventoryItem::where('component_variation_id', $variation->id)
                ->orderBy('id', 'desc')
                ->first();
            $componentStock = new ComponentStock();
            $componentStock->id = $variation->id;
            $componentStock->stock = isset($lastRecordInventoryItem->stock) 
            ? $lastRecordInventoryItem->stock 
            : ($variation->stock ? $variation->stock : 0);
            $componentStock->alert_stock = $variation->alert_stock;
            $componentStock->store_id = $variation->store_id;
            $componentStock->component_variation_id = $variation->id;
            $componentStock->save();
          }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('component_stock');
    }
}
