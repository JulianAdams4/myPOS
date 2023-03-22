<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpotTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('spot_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code')->nullable(false);
            $table->unsignedInteger('defaultWidth')->nullable(false);
            $table->unsignedInteger('defaultHeight')->nullable(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('spot_types');
    }
}
