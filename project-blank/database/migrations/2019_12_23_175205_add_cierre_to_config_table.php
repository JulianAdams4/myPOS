<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\StoreConfig;

class AddCierreToConfigTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->json('cierre');
        });

        StoreConfig::chunk(200, function ($configs) {
            foreach ($configs as $config) {
                $config->cierre = '[{"cmd":"PRINT_CASHIER_SUMMARY","payload":{"text":""}},{"cmd":"ALIGN","payload":{"alignment":"CENTER"}},{"cmd":"PRINT_TEXT","payload":{"text":"-----------------------------------"}},{"cmd":"PRINT_SALES_SUMMARY","payload":{"text":""}},{"cmd":"ALIGN","payload":{"alignment":"CENTER"}},{"cmd":"PRINT_TEXT","payload":{"text":"-----------------------------------"}},{"cmd":"PRINT_TEXT","payload":{"text":"Detalle de Ventas"}},{"cmd":"PRINT_TEXT","payload":{"text":"-----------------------------------"}},{"cmd":"ALIGN","payload":{"alignment":"LEFT"}},{"cmd":"PRINT_SALES_DETAILS","payload":{"text":""}},{"cmd":"ALIGN","payload":{"alignment":"CENTER"}},{"cmd":"PRINT_TEXT","payload":{"text":"-----------------------------------"}},{"cmd":"PRINT_TEXT","payload":{"text":"Detalle de Caja"}},{"cmd":"PRINT_TEXT","payload":{"text":"-----------------------------------"}},{"cmd":"PRINT_CASHIER_DETAILS","payload":{"text":""}},{"cmd":"FEED","payload":{"lines":2}},{"cmd":"CUT","payload":{"mode":"FULL"}}]';
                $config->save();
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
        Schema::table('store_configs', function (Blueprint $table) {
            $table->dropColumn('cierre');
        });
    }
}
