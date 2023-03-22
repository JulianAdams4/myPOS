<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\InventoryAction;

class AddCodeToInventoryActions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('inventory_actions', function (Blueprint $table) {
            $table->string('code')->nullable();
        });
        $receive = InventoryAction::where('name', 'Existencias recibidas')->first();
        if (!$receive) {
            $receive = new InventoryAction();
            $receive->name = 'Existencias recibidas';
            $receive->action = 1;
        }
        $receive->code = 'receive';
        $receive->save();
        $count = InventoryAction::where('name', 'Recuento de inventario')->first();
        if (!$count) {
            $count = new InventoryAction();
            $count->name = 'Recuento de inventario';
            $count->action = 2;
        }
        $count->code = 'count';
        $count->save();
        $damaged = InventoryAction::where('name', 'Daño')->first();
        if (!$damaged) {
            $damaged = new InventoryAction();
            $damaged->name = 'Daño';
            $damaged->action = 3;
        }
        $damaged->code = 'damaged';
        $damaged->save();
        $stolen = InventoryAction::where('name', 'Robo')->first();
        if (!$stolen) {
            $stolen = new InventoryAction();
            $stolen->name = 'Robo';
            $stolen->action = 3;
        }
        $stolen->code = 'stolen';
        $stolen->save();
        $lost = InventoryAction::where('name', 'Pérdida')->first();
        if (!$lost) {
            $lost = new InventoryAction();
            $lost->name = 'Pérdida';
            $lost->action = 3;
        }
        $lost->code = 'lost';
        $lost->save();
        $return = InventoryAction::where('name', 'Devolución de artículos reabastecidos')->first();
        if (!$return) {
            $return = new InventoryAction();
            $return->name = 'Devolución de artículos reabastecidos';
            $return->action = 1;
        }
        $return->code = 'return';
        $return->save();
        $sendTransfer = InventoryAction::where('name', 'Enviar a otra tienda')->first();
        if (!$sendTransfer) {
            $sendTransfer = new InventoryAction();
            $sendTransfer->name = 'Enviar a otra tienda';
            $sendTransfer->action = 3;
        }
        $sendTransfer->code = 'send_transfer';
        $sendTransfer->save();
        $receiveTransfer = InventoryAction::where('name', 'Recibir de otra tienda')->first();
        if (!$receiveTransfer) {
            $receiveTransfer = new InventoryAction();
            $receiveTransfer->name = 'Recibir de otra tienda';
            $receiveTransfer->action = 1;
        }
        $receiveTransfer->code = 'receive_transfer';
        $receiveTransfer->save();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('inventory_actions', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
}
