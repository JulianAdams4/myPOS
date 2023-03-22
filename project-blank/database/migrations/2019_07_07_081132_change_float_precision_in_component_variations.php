<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeFloatPrecisionInComponentVariations extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('component_variations', function (Blueprint $table) {
            $table->decimal('cost', 14, 4)->change();
            $table->decimal('value', 14, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('component_variations', function (Blueprint $table) {
            //
        });
    }
}
