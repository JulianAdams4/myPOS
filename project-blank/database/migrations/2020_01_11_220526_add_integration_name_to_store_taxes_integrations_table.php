<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddIntegrationNameToStoreTaxesIntegrationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_taxes_integrations', function (Blueprint $table) {
            $table->string('integration_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('store_taxes_integrations', function (Blueprint $table) {
            $table->dropColumn('integration_name');
        });
    }
}
