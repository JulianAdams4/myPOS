<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\InventoryAction;

class RenameStolenInventoryAction extends Migration
{
    /**
     * Run the migrations.
     *
     * Change "Robo" to "Consumo Interno"
     */
    public function up()
    {
        $stolen = InventoryAction::firstOrCreate(
            ['name' => "Robo"],
            ['action' => 3, 'code' => "stolen"]
        );
        $stolen->name = "Consumo Interno";
        $stolen->alias = "Consumo Interno";
        $stolen->save();
    }

    /**
     * Reverse the migrations.
     *
     * Change "Consumo Interno" to "Robo"
     */
    public function down()
    {
        $intern_consumption = InventoryAction::firstOrCreate(
            ['name' => "Consumo Interno"],
            ['action' => 3, 'code' => "stolen"]
        );
        $intern_consumption->name = "Robo";
        $intern_consumption->alias = "Robo";
        $intern_consumption->save();
    }
}
