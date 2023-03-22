<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\Spot;
use App\Store;
use App\StoreLocations;

class SetDefaultLocationForSpots extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // All previous locations, priority = 0
        StoreLocations::chunk(20, function ($oldLocations) {
            foreach ($oldLocations as $old) {
                $old->priority = 0;
                $old->save();
            }
        });
        // For all stores
        Store::chunk(50, function ($stores) {
            foreach ($stores as $store) {
                $storeId = $store->id;
                // Create a default location
                $location = new StoreLocations();
                $location->name = 'Piso 1';
                $location->store_id = $storeId;
                $location->priority = 1;
                $location->save();
                // Update spots to default location
                Spot::where('store_id', $storeId)->update(
                    ['location_id' => $location->id]
                );
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
        // All location_id in spots to null
        DB::table('spots')->update(array('location_id' => null));
        // Remove all default locations
        StoreLocations::where('name', 'Piso 1')->delete();
        // All previous locations, priority = 1
        StoreLocations::chunk(20, function ($locations) {
            foreach ($locations as $loc) {
                $loc->priority = 1;
                $loc->save();
            }
        });
    }
}
