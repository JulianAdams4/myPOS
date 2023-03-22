<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateInvoiceIntegrationDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('invoice_integration_details', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('invoice_id')->unsigned();
            $table->foreign('invoice_id')->references('id')
                ->on('invoices')->onDelete('cascade');

            $table->string('external_id')->nullable();
            $table->string('integration');
            $table->string('status');

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
        Schema::dropIfExists('invoice_integration_details');
    }
}
