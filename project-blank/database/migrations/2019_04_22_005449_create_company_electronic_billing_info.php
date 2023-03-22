<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCompanyElectronicBillingInfo extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('company_electronic_billing_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->string('data_for')->default("testing");
            $table->unsignedInteger('env_prod')->default(0);
            $table->string('special_contributor')->nullable();
            $table->boolean('accounting_needed')->default(false);
            $table->string('business_name');
            $table->string('tradename');
            $table->string('address');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
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
        Schema::dropIfExists('company_electronic_billing_details');
    }
}
