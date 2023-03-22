<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTaxSubtotalToInvoiceTaxDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('invoice_tax_details', function (Blueprint $table) {
            $table->float('tax_subtotal')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('invoice_tax_details', function (Blueprint $table) {
            $table->dropColumn('tax_subtotal');
        });
    }
}
