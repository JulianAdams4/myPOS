<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddElectronicBillingDataStore extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('currency')->default('USD');
            $table->string('issuance_point')->nullable();
            $table->string('code')->nullable();
            $table->string('address')->nullable();
            $table->string('country_code')->nullable();
            $table->unsignedInteger('bill_sequence')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('stores', function (Blueprint $table) {
            //
        });
    }
}
