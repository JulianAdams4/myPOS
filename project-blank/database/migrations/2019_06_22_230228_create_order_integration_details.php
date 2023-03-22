<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrderIntegrationDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('order_integration_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_id')->nullable();
            $table->string('integration_name');
            $table->string('external_order_id')->nullable();
            $table->string('external_store_id')->nullable();
            $table->string('external_customer_id')->nullable();
            $table->timestamp('external_created_at')->nullable();
            $table->unsignedInteger('billing_id')->nullable();
            $table->unsignedInteger('number_items')->default(0);
            $table->unsignedInteger('value')->default(0);
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
        Schema::dropIfExists('order_integration_details');
    }
}
