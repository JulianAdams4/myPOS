<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddCustomerNameAndOrderNumberToOrderIntegrationDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('order_integration_details', function (Blueprint $table) {
            $table->text('customer_name')->nullable();
            $table->text('order_number')->nullable();
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
            $table->dropColumn('customer_name');
            $table->dropColumn('order_number');
        });
    }
}
