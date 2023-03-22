<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrderHasPaymentIntegrationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('order_has_payment_integrations', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores');

            $table->unsignedInteger('order_id')->nullable();
            $table->foreign('order_id')->references('id')->on('orders');

            $table->string('integration_name');
            $table->decimal('amount', 14, 4)->default(0);
            $table->string('currency')->nullable();
            $table->string('message')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('status')->nullable();
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
        Schema::dropIfExists('order_has_payment_integrations');
    }
}
