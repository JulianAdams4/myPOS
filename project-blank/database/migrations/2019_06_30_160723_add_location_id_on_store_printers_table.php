<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddLocationIdOnStorePrintersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_printers', function (Blueprint $table) {
            $table->unsignedInteger('store_locations_id')->nullable();
            $table->foreign('store_locations_id')->references('id')->on('store_locations')->onDelete('set null');
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
            $table->dropForeign(['store_locations_id']);
            $table->dropColumn('store_locations_id');
        });
    }
}
