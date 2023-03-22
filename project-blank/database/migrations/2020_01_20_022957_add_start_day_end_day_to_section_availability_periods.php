<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddStartDayEndDayToSectionAvailabilityPeriods extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('section_availability_periods', function (Blueprint $table) {
            $table->integer('start_day')->nullable();
            $table->integer('end_day')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('section_availability_periods', function (Blueprint $table) {
            $table->dropColumn('start_day');
            $table->dropColumn('end_day');
        });
    }
}
