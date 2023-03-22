<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSubscriptionInvoiceDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('subscription_invoice_details', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('store_id')->unsigned();
            $table->foreign('store_id')->references('id')->on('stores');

            $table->unsignedInteger('subscription_plan_id')->unsigned();
            $table->foreign('subscription_plan_id')->references('id')->on('subscription_plans');

            $table->unsignedInteger('subs_invoice_id');
            $table->foreign('subs_invoice_id')->references('id')->on('subscription_invoices')->onDelete('cascade');
            
            $table->string('description');
            $table->decimal('subtotal', 14, 4)->default(0);
            $table->decimal('discounts', 14, 4)->default(0);
            $table->decimal('total_taxes', 14, 4)->default(0);
            $table->decimal('total', 14, 4)->default(0);

            $table->dateTime('subs_start');
            $table->dateTime('subs_end');

            $table->enum('status', ['paid', 'pending', 'failed'])->default('pending');

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
        Schema::dropIfExists('subscription_invoice_details');
    }
}
