<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOnDeleteCascadeToInvoiceItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign('invoice_items_order_detail_id_foreign');
            $table->foreign('order_detail_id')->references('id')->on('order_details')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign('invoice_items_order_detail_id_foreign');
            $table->foreign('order_detail_id')->references('id')->on('order_details');
        });
    }
}
