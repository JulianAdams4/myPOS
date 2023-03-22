<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\HistoricalInventoryItem;
use App\ComponentStock;

class AddOldStockToHistoricalInventoryItems extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('historical_inventory_items', function (Blueprint $table) {
            $table->float('old_stock', 8, 2)->default(0);
        });
        $componentsStock = ComponentStock::all();
        foreach ($componentsStock as $componentStock) {
            $allHistorical = HistoricalInventoryItem::where(
                "component_stock_id",
                $componentStock->id
            )
            ->orderBy("id", "asc")
            ->get();
            $previousStock = 0;
            foreach ($allHistorical as $historical) {
                $historical->old_stock = $previousStock;
                $historical->save();
                $newStock = $historical->stock - $historical->consumption;
                if ($newStock < 0) {
                    $newStock = 0;
                }
                $previousStock = $newStock;
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('historical_inventory_items', function (Blueprint $table) {
            $table->dropColumn('old_stock');
        });
    }
}
