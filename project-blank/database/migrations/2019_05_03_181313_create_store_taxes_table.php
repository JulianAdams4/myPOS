<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateStoreTaxesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('store_taxes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('store_id');
			$table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->string('name');
            $table->float('percentage', 6, 2)->default(0.00);
            $table->enum('type', ['included', 'additional', 'invoice'])->default('included');
            $table->boolean('enabled')->default(true);
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
        Schema::dropIfExists('store_taxes');
    }
}
