<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddExternalsToProductIntegrationDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('product_integration_details', function (Blueprint $table) {
            $table->string('external_id')->nullable();
            $table->string('external_code')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('product_integration_details', function (Blueprint $table) {
            $table->dropColumn('external_id');
            $table->dropColumn('external_code');
        });
    }
}
