<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddShowEmployeeComandaDigitalStoreConfigsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->boolean('employee_digital_comanda')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->dropColumn('employee_digital_comanda');
        });
    }
}
