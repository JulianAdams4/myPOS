<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpecificationExternalIdsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('specification_external_ids', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('specification_id');
            $table->foreign('specification_id')->references('id')->on('specifications')->onDelete('cascade');
            $table->integer('integration_id')->unsigned();
            $table->foreign('integration_id')->references('id')
                ->on('available_mypos_integrations')->onDelete('cascade');
            $table->string("external_id");
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
        Schema::dropIfExists('specification_external_ids');
    }
}
