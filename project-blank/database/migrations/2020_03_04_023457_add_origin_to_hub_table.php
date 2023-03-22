<?php

use App\Order;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOriginToHubTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('hubs', function (Blueprint $table) {
            $table->tinyInteger('spot_origin')->default(1);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('order_duration')->default(900)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('hubs', function (Blueprint $table) {
            $table->dropColumn('spot_origin');
        });
    }
}
