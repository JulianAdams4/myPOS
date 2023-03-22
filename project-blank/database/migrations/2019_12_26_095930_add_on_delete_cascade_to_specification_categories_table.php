<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOnDeleteCascadeToSpecificationCategoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('specification_categories', function (Blueprint $table) {
            $table->dropForeign('specification_categories_section_id_foreign');
            $table->foreign('section_id')->references('id')->on('sections')
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
        Schema::table('specification_categories', function (Blueprint $table) {
            $table->dropForeign('specification_categories_section_id_foreign');
            $table->foreign('section_id')->references('id')->on('sections');
        });
    }
}
