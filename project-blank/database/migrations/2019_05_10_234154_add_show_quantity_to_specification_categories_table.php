<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddShowQuantityToSpecificationCategoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('specification_categories', function (Blueprint $table) {
            $table->boolean('show_quantity')->default(true);
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
            $table->dropColumn("show_quantity");            
        });
    }
}
