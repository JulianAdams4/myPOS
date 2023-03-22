<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\InventoryAction;
use App\StockMovement;

class MoveExpirationDateFromHistoricalInventoryItemsToStockMovements extends Migration
{
    /**
     * Run the migrations.
     * @return void
     */
    public function up()
    {
        /**
         *  Nota: Los rows del Historical y StockMovement comparten ciertas columnas
         *  tales como: 'component_stock_id', 'inventory_action_id' y 'created_at'
         *  Por esto, podemos hallar 1 y solo 1 row en StockMovement y a este row,
         *  le asignamos el 'expiration_date'
         */
        DB::table('historical_inventory_items')->whereNotNull('expiration_date') ->chunkById(50, function ($records) {
            foreach ($records as $record) {
                $movement = StockMovement::where([
                    ['component_stock_id',  '=', $record->component_stock_id],
                    ['inventory_action_id', '=', $record->inventory_action_id],
                    ['created_at',          '=', $record->created_at]
                ])->first();
                if ($movement) {
                    $movement->expiration_date = $record->expiration_date;
                    $movement->save();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Regresamos la columna 'expiration_date' a null
        DB::table('stock_movements')->whereNotNull('id')->update(['expiration_date' => null]);
    }
}
