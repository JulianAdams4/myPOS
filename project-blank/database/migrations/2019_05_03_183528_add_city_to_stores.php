<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddCityToStores extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
	{
		Schema::table('stores', function (Blueprint $table) {
			$table->unsignedInteger('city_id')->nullable();
			$table->foreign('city_id')->references('id')->on('cities')->onDelete('cascade');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::table('stores', function (Blueprint $table) {
			$table->dropForeign(['city_id']);
			$table->dropColumn('city_id');
		});
	}
}
