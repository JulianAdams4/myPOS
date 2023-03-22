<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeFloatPrecisionInInvoices extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('subtotal', 14, 4)->change();
            $table->decimal('tax', 14, 4)->change();
            $table->decimal('total', 14, 4)->change();
            $table->decimal('discount_percentage', 5, 2)->change();
            $table->decimal('discount_value', 14, 4)->change();
            $table->decimal('undiscounted_subtotal', 14, 4)->change();
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
            //
        });
    }
}
