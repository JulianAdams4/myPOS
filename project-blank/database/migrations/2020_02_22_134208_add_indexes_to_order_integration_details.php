<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddIndexesToOrderIntegrationDetails extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('order_integration_details', function (Blueprint $table) {
            $table->index('order_id', 'order_id_index');
            $table->index('external_order_id', 'external_order_id_index');
            $table->index('external_store_id', 'external_store_id_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('order_integration_details', function (Blueprint $table) {
            $table->dropIndex('order_id_index');
            $table->dropIndex('external_order_id_index');
            $table->dropIndex('external_store_id_index');
        });
    }
}
