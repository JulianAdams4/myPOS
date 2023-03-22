<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\InventoryAction;

class AddAliasToInventoryActions extends Migration
{
    /*********************
     * Run the migrations.
     *********************/
    public function up()
    {
        /**
         *  Add new column
         */
        Schema::table('inventory_actions', function (Blueprint $table) {
            $table->string('alias')->default("");
        });
        /**
         *  Fill column
         */
        $receive = InventoryAction::firstOrCreate(
            ['name' => "Existencias recibidas"],
            ['action' => 1, 'code' => "receive"]
        );
        $receive->alias = "Ingreso";
        $receive->save();
        // -------------------------------------
        $count = InventoryAction::firstOrCreate(
            ['name' => "Recuento de inventario"],
            ['action' => 2, 'code' => "count"]
        );
        $count->alias = "Recuento";
        $count->save();
        // ---------------------------------------
        $damaged = InventoryAction::firstOrCreate(
            ['name' => "Daño"],
            ['action' => 3, 'code' => "damaged"]
        );
        $damaged->alias = "Daño";
        $damaged->save();
        // --------------------------------------
        $stolen = InventoryAction::firstOrCreate(
            ['name' => "Robo"],
            ['action' => 3, 'code' => "stolen"]
        );
        $stolen->alias = "Robo";
        $stolen->save();
        // ------------------------------------
        $lost = InventoryAction::firstOrCreate(
            ['name' => "Pérdida"],
            ['action' => 3, 'code' => "lost"]
        );
        $lost->alias = "Pérdida";
        $lost->save();
        // --------------------------------------
        $return = InventoryAction::firstOrCreate(
            ['name' => "Devolución de artículos reabastecidos"],
            ['action' => 1, 'code' => "return"]
        );
        $return->alias = "Devolución";
        $return->save();
        // ---------------------------------------------
        $send_transfer = InventoryAction::firstOrCreate(
            ['name' => "Enviar a otra tienda"],
            ['action' => 3, 'code' => "send_transfer"]
        );
        $send_transfer->alias = "Transferencia";
        $send_transfer->save();
        // ------------------------------------------------
        $receive_transfer = InventoryAction::firstOrCreate(
            ['name' => "Recibir de otra tienda"],
            ['action' => 1, 'code' => "receive_transfer"]
        );
        $receive_transfer->alias = "Transferencia";
        $receive_transfer->save();
        // -------------------------------------------------
        $order_consumption = InventoryAction::firstOrCreate(
            ['name' => "Consumo por orden"],
            ['action' => 3, 'code' => "order_consumption"]
        );
        $order_consumption->alias = "Consumo";
        $order_consumption->save();
        // -------------------------------------------
        $update_cost = InventoryAction::firstOrCreate(
            ['name' => "Actualización de costo"],
            ['action' => 2, 'code' => "update_cost"]
        );
        $update_cost->alias = "Actualización de costo";
        $update_cost->save();
        // ---------------------------------------------
        $revoked_order = InventoryAction::firstOrCreate(
            ['name' => "Anulación de orden"],
            ['action' => 1, 'code' => "revoked_order"]
        );
        $revoked_order->alias = "Anulación";
        $revoked_order->save();
        // --------------------------------------------------------
        $create_order_consumption = InventoryAction::firstOrCreate(
            ['name' => "Agregar stock del insumo elaborado por orden de producción"],
            ['action' => 1, 'code' => "create_order_consumption"]
        );
        $create_order_consumption->alias = "Producción creada";
        $create_order_consumption->save();
        // ----------------------------------------------------------
        $revert_stock_revoked_order = InventoryAction::firstOrCreate(
            ['name' => "Revertir stock del insumo elaborado por cancelamiento de orden de producción"],
            ['action' => 3, 'code' => "revert_stock_revoked_order"]
        );
        $revert_stock_revoked_order->alias = "Producción cancelada";
        $revert_stock_revoked_order->save();
        // ------------------------------------------------
        $invoice_provider = InventoryAction::firstOrCreate(
            ['name' => "Ingreso de Factura de proveedor"],
            ['action' => 1, 'code' => "invoice_provider"]
        );
        $invoice_provider->alias = "Factura de proveedor";
        $invoice_provider->save();
    }

    /**************************
     * Reverse the migrations.
     **************************/
    public function down()
    {
        Schema::table('inventory_actions', function (Blueprint $table) {
            $table->dropColumn('alias');
        });
    }
}
