<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOnDeleteCascadeToSectionAvailabilityPeriodsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('section_availability_periods', function (Blueprint $table) {
            $table->dropForeign('section_availability_periods_section_availability_id_foreign');
            $table->foreign('section_availability_id')->references('id')->on('section_availabilities')
                ->onDelete('cascade');
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
            $table->dropForeign('section_availability_periods_section_availability_id_foreign');
            $table->foreign('section_availability_id')->references('id')->on('section_availabilities');
        });
    }
}
