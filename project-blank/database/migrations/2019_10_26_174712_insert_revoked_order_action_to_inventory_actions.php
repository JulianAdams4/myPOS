<?php

use Illuminate\Database\Migrations\Migration;
use Carbon\Carbon;
use App\InventoryAction;

class InsertRevokedOrderActionToInventoryActions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
		$newAction = new InventoryAction();
		$newAction->name = "Anulación de orden";
		$newAction->action = 1;
		$newAction->code = "revoked_order";
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
		InventoryAction::where('code', 'revoked_order')->delete();
    }
}
