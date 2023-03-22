<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeFloatPrecisionInOrderTaxDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('order_tax_details', function (Blueprint $table) {
            $table->decimal('subtotal', 14, 4)->change();
            $table->decimal('tax_subtotal', 14, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('order_tax_details', function (Blueprint $table) {
            //
        });
    }
}
