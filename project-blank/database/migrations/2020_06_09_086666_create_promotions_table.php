<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePromotionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('company_id');
            $table->string('name');
            $table->integer('promotion_type_id')->unsigned()->nullable();
            $table->foreign('promotion_type_id')->references('id')->on('promotion_types')->onDelete('cascade');
            $table->integer('discount_type_id')->nullable();
            $table->boolean('is_entire_menu')->default(false);
            $table->boolean('requiered_recipe')->default(false);
            $table->boolean('is_unlimited')->default(false);
            $table->decimal('condition_value',10,4)->default(0);//cuando se escoge promoción condicionada a valor.
            $table->integer('max_apply')->nullable();
            $table->integer('times_applied')->nullable();
            
            $table->date('from_date');
            $table->date('to_date');
            $table->time('from_time');
            $table->time('to_time');
            $table->char('status',1);
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
        Schema::dropIfExists('promotions');
    }
}
