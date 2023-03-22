<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateFranchisesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('franchises', function (Blueprint $table) {
            //
            $table->increments('id');

            $table->unsignedInteger('origin_company_id')->unsigned();
            $table->foreign('origin_company_id')->references('id')->on('companies');

            $table->unsignedInteger('company_id')->unsigned();
            $table->foreign('company_id')->references('id')->on('companies');

            $table->timestamps();
            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('franchises');
    }
}
