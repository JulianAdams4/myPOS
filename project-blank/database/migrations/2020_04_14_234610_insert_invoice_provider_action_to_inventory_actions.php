<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Carbon\Carbon;
use App\InventoryAction;

class InsertInvoiceProviderActionToInventoryActions extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
		$newAction = new InventoryAction();
		$newAction->name = "Ingreso de Factura de proveedor";
		$newAction->action = 1; // Añadir
        $newAction->code = "invoice_provider";
		$newAction->created_at = Carbon::now()->toDateTimeString();
		$newAction->updated_at = Carbon::now()->toDateTimeString();
		$newAction->save();
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
		InventoryAction::where('code', 'invoice_provider')->delete();
    }
}
