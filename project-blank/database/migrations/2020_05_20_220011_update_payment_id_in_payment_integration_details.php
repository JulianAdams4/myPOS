<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdatePaymentIdInPaymentIntegrationDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('payment_integration_details', function (Blueprint $table) {
            $table->unsignedInteger('payment_id')->nullable()->change();
            $table->foreign('payment_id')->references('id')->on('payments');
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
            $table->dropForeign(['payment_id']);
        });

        Schema::table('payment_integration_details', function (Blueprint $table) {
            $table->integer('payment_id')->change();
        });
    }
}
