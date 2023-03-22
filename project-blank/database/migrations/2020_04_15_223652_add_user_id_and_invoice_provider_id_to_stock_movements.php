<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddUserIdAndInvoiceProviderIdToStockMovements extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->unsignedInteger('invoice_provider_id')->nullable();
            $table->foreign('invoice_provider_id')->references('id')->on('invoice_providers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['invoice_provider_id']);
            $table->dropColumns(['user_id', 'invoice_provider_id']);
        });
    }
}
