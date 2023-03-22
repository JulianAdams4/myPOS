<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeIntPrecisionInProviderDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('invoice_provider_details', function (Blueprint $table) {
            $table->decimal('unit_price', 14, 4)->change();
            $table->decimal('discount', 14, 4)->change();
            $table->decimal('tax', 14, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('invoice_provider_details', function (Blueprint $table) {
            //
        });
    }
}
