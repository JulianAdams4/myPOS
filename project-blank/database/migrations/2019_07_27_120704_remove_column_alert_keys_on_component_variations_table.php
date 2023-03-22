<?php

use App\ComponentVariation;
use App\ComponentStock;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RemoveColumnAlertKeysOnComponentVariationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('component_variations', function (Blueprint $table) {
            $table->dropForeign('component_variations_store_id_foreign');
            $table->dropColumn('store_id');
            $table->dropColumn('stock');
            $table->dropColumn('alert_stock');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('component_variations', function (Blueprint $table) {
            $table->unsignedInteger('store_id')->index();
            $table->float('stock', 8, 2)->default(0);
            $table->unsignedInteger('alert_stock')->default(1)->nullable();
        });

        $data = ComponentStock::select('id', 'stock', 'alert_stock', 'store_id', 'component_variation_id')->get();
        
        foreach ($data as $stockDB) {
            $variation = ComponentVariation::find($stockDB->component_variation_id);
            if (!$variation) {
                continue;
            }

            $variation->stock = $stockDB->stock;
            $variation->alert_stock = $stockDB->alert_stock;
            $variation->store_id = $stockDB->store_id;
            $variation->save();
        }

        // Schema::table('component_variations', function (Blueprint $table) {
        //     $table->foreign('store_id')
        //         ->references('id')->on('stores')->onDelete('cascade');
        // });
    }
}