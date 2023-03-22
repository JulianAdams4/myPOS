<?php

use Illuminate\Database\Migrations\Migration;
use Carbon\Carbon;
use App\InventoryAction;

class InsertUpdateCostActionToInventoryActions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
		$newAction = new InventoryAction();
		$newAction->name = "Actualización de costo";
		$newAction->action = 2;
		$newAction->code = "update_cost";
		$newAction->created_at = Carbon::now()->toDateTimeString();
		$newAction->updated_at = Carbon::now()->toDateTimeString();
		$newAction->save();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
		InventoryAction::where('code', 'update_cost')->delete();
    }
}
