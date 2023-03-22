<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddRestrictiveStockToStoreConfigs extends Migration
{
    /** ==========================================================================
     *  Run the migrations
     * ===========================================================================
     * 'Restrictive Stock' permite la facturación, ventas y movimientos de stock
     *  siempre y cuando haya suficientes existencias. Si no hay suficiente stock,
     *  lanza un error e impide la operación.
     *  Es aplicable a:
     *  - Producciones (& Transferencias)
     *  - Ventas
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->boolean('restrictive_stock_production')->default(false);
            $table->boolean('restrictive_stock_sales')->default(false);
        });
    }

    /** =================================
     * Reverse the migrations
     *===================================
     * @return void
     */
    public function down()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->dropColumns([
                'restrictive_stock_production',
                'restrictive_stock_sales'
            ]);
        });
    }
}
