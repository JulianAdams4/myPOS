<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddChainAndBranchIdsToStoreIntegrationIds extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_integration_ids', function (Blueprint $table) {
            $table->string('restaurant_chain_external_id')->nullable();
            $table->string('restaurant_branch_external_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('store_integration_ids', function (Blueprint $table) {
            $table->dropColumn('restaurant_chain_external_id');
            $table->dropColumn('restaurant_branch_external_id');
        });
    }
}
