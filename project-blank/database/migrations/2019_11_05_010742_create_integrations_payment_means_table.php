<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateIntegrationsPaymentMeansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('integrations_payment_means', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('integration_id');
            $table->foreign('integration_id')->references('id')->on('available_mypos_integrations')->onDelete('cascade');
            $table->string('name_integration');
            $table->string('external_payment_mean_code');
            $table->string('local_payment_mean_code');
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
        Schema::dropIfExists('integrations_payment_means');
    }
}
