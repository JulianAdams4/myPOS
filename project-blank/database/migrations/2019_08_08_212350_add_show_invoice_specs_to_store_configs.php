<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddShowInvoiceSpecsToStoreConfigs extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->boolean('show_invoice_specs')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->dropColumn('show_invoice_specs');
        });
    }
}
