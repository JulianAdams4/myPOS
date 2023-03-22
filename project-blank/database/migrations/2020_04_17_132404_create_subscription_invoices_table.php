<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSubscriptionInvoicesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->increments('id');

            $table->string('external_invoice_id');
            $table->string('integration_name');

            $table->decimal('subtotal', 14, 4)->default(0);
            $table->decimal('discounts', 14, 4)->default(0);
            $table->decimal('total_taxes', 14, 4)->default(0);
            $table->decimal('total', 14, 4)->default(0);

            $table->enum('status', ['paid', 'pending', 'failed']);
            $table->dateTime('billing_date')->nullable();
            
            $table->unsignedInteger('company_id')->unsigned();
            $table->foreign('company_id')->references('id')->on('companies');
            
            $table->string('country');

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
        Schema::dropIfExists('subscription_invoices');
    }
}
