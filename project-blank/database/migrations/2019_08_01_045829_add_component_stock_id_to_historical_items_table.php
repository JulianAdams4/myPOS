<?php

use App\ComponentStock;
use App\HistoricalInventoryItem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddComponentStockIdToHistoricalItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('historical_inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('component_stock_id')->nullable();
            $table->foreign('component_stock_id')
                ->references('id')->on('component_stock')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('historical_inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('component_variation_id')->nullable();
            $table->foreign('component_variation_id')
                ->references('id')->on('component_variations')->onDelete('cascade');
        });

        $data = ComponentStock::select('id', 'component_variation_id')->get();
        foreach ($data as $stockDB) {
            $history = HistoricalInventoryItem::where('component_stock_id', $stockDB->id)->get();
            if (!$history) {
                continue;
            }

            foreach ($history as $historicalItem) {
                $historicalItem->component_variation_id = $stockDB->component_variation_id;
                $historicalItem->save();
            }
        }

        Schema::table('historical_inventory_items', function (Blueprint $table) {
            $table->dropForeign('historical_inventory_items_component_stock_id_foreign');
            $table->dropColumn('component_stock_id');
        });
    }
}
