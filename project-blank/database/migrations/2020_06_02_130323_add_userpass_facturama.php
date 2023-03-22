<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddUserpassFacturama extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_integration_ids', function (Blueprint $table) {
            $table->string('username', 100)->nullable()->default(null);
            $table->string('password', 100)->nullable()->default(null);
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
            $table->dropColumn("username");
            $table->dropColumn("password");
        });
    }
}
