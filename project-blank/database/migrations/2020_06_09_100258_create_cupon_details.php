<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCuponDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cupon_details', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('cupon_id')->unsigned();
            $table->foreign('cupon_id')->references('id')->on('cupons')->onDelete('cascade');
            $table->string('cupon_code');
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
        Schema::dropIfExists('cupon_details');
    }
}
