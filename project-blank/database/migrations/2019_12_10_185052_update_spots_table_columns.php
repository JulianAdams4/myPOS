<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateSpotsTableColumns extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->string('type')->default('square4Normal');
            $table->unsignedInteger('width')->default(129);
            $table->unsignedInteger('height')->default(122);
            $table->unsignedInteger('coordX')->default(0);
            $table->unsignedInteger('coordY')->default(0);
            $table->boolean('clickable')->default(true);
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
            $table->dropColumn([
                'type',
                'width',
                'height',
                'coordX',
                'coordY',
                'clickable'
            ]);
        });
    }
}
