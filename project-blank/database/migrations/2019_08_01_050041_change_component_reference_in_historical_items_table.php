<?php

use App\ComponentStock;
use App\HistoricalInventoryItem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeComponentReferenceInHistoricalItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $data = HistoricalInventoryItem::select('id', 'component_variation_id', 'component_stock_id')->get();
        foreach ($data as $historicalItem) {
            $stockDB = ComponentStock::find($historicalItem->component_variation_id);
            if(!$stockDB) {
                continue;
            }
            
            $historicalItem->component_stock_id = $stockDB->id;
            $historicalItem->save();
        }

        Schema::table('historical_inventory_items', function (Blueprint $table) {
            $table->dropForeign('historical_inventory_items_component_variation_id_foreign');
            $table->dropColumn('component_variation_id');
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
            //
        });
    }
}
