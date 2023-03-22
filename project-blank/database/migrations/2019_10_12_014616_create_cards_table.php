<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCardsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('type');
            $table->timestamps();
        });

        Schema::create('card_store', function (Blueprint $table) {
            $table->integer('card_id')->unsigned();
            $table->integer('store_id')->unsigned();

            $table->unique(['card_id', 'store_id']);
            $table->foreign('card_id')->references('id')->on('cards')
                ->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('store_id')->references('id')->on('stores')
                ->onDelete('cascade')->onUpdate('cascade');
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
        Schema::dropIfExists('card_store');
        Schema::dropIfExists('cards');
    }
}
