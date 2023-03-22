<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RemoveCreditCardActiveDatetimeFromInvoices extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('credit_card');
            $table->dropColumn('active');
            $table->dropColumn('invoice_datetime');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('credit_card')->nullable();
            $table->dateTime('invoice_datetime')->nullable();
            $table->boolean('active')->nullable();
        });
    }
}
