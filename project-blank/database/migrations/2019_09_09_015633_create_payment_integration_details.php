<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePaymentIntegrationDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('payment_integration_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores');
            $table->string('integration_name');
            $table->string('cin')->nullable();
            $table->decimal('amount', 14, 4)->default(0);
            $table->string('currency')->nullable();
            $table->string('message')->nullable();
            $table->unsignedInteger('reference_id')->nullable();
            $table->unsignedInteger('payment_id')->nullable();
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
        Schema::dropIfExists('payment_integration_details');
    }
}
