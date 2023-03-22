<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOnDeleteCascadeToSectionAvailabilitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('section_availabilities', function (Blueprint $table) {
            $table->dropForeign('section_availabilities_section_id_foreign');
            $table->foreign('section_id')->references('id')->on('sections')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('section_availabilities', function (Blueprint $table) {
            $table->dropForeign('section_availabilities_section_id_foreign');
            $table->foreign('section_id')->references('id')->on('sections');
        });
    }
}
