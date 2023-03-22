<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddLocationIdToSpotsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->unsignedInteger('location_id')->nullable();
            $table->foreign('location_id')->references('id')->on('store_locations');
        });
        Schema::table('store_locations', function (Blueprint $table) {
            $table->unsignedInteger('priority')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
        Schema::table('store_locations', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
}
