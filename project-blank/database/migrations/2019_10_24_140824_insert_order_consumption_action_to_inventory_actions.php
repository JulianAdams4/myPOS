<?php

use Illuminate\Database\Migrations\Migration;
use Carbon\Carbon;
use App\InventoryAction;

class InsertOrderConsumptionActionToInventoryActions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
		$newAction = new InventoryAction();
		$newAction->name = "Consumo por orden";
		$newAction->action = 3;
		$newAction->code = "order_consumption";
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
		InventoryAction::where('code', "order_consumption")->delete();
    }
}
