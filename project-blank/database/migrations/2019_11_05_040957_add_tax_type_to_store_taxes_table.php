<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTaxTypeToStoreTaxesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_taxes', function (Blueprint $table) {
            $table->integer('tax_type')->nullable();
            $table->index('tax_type');
            $table->foreign('tax_type')->references('code')->on('taxes_types');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('store_taxes', function (Blueprint $table) {
            $table->dropForeign(['tax_type']);
            $table->dropColumn('tax_type');
        });
    }
}
