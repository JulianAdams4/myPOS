<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOrderPaymentIntegrationToPaymentIntegrationDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('payment_integration_details', function (Blueprint $table) {
            $table->unsignedInteger('order_payment_integration')->nullable();
            $table->foreign('order_payment_integration')->references('id')->on('order_has_payment_integrations');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('payment_integration_details', function (Blueprint $table) {
            $table->dropForeign('order_payment_integration');
            $table->dropColumn('order_payment_integration');
        });
    }
}
