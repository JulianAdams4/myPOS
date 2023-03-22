<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddFileUrlAndTypeToInvoiceProviders extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('invoice_providers', function (Blueprint $table) {
            $table->text('file_url')->nullable();
            $table->text('file_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('invoice_providers', function (Blueprint $table) {
            $table->dropColumn(['file_url', 'file_type']);
        });
    }
}
