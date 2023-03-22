<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddInterfaceAndConnectorToStorePrinters extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_printers', function (Blueprint $table) {
            $table->string('interface');
            $table->integer('connector');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('store_printers', function (Blueprint $table) {
            $table->dropColumn(['interface', 'connector']);
        });
    }
}
