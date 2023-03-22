<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('customer_id')->nullable();
            $table->unsignedInteger('store_id');
            $table->unsignedInteger('spot_id');
            $table->unsignedInteger('address_id')->nullable();
            $table->unsignedInteger('billing_id')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedInteger('route_value')->default(0)->nullable();
            $table->unsignedInteger('order_value')->default(0);
            $table->string('order_token')->nullable();
            $table->string('current_status')->nullable();
            $table->boolean('status')->default(true);
            $table->unsignedInteger('employee_id');
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('spot_id')->references('id')->on('spots')->onDelete('cascade');
            $table->foreign('address_id')->references('id')->on('addresses');
            $table->foreign('billing_id')->references('id')->on('billings');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('orders');
    }
}
