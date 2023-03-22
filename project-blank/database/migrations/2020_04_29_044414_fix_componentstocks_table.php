<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

use App\Component;
use App\ComponentStock;
use Carbon\Carbon;

class FixComponentstocksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Component::with('category.company.stores')->chunk(45, function ($components) {
            foreach ($components as $component) {
                $stores = $component->category->company->stores;
                foreach ($stores as $store) {
                    $componentStock = ComponentStock::where('store_id', $store->id)
                        ->where('component_id', $component->id)
                        ->first();
                    
                    if ($componentStock == null) {
                        DB::table('component_stock')->insert([
                            'store_id' => $store->id,
                            'component_id' => $component->id,
                            'alert_stock' => 0,
                            'stock' => 0,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now()
                        ]);
                    }
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
        //
    }
}
