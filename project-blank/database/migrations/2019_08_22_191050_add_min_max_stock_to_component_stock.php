<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddMinMaxStockToComponentStock extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('component_stock', function (Blueprint $table) {
            $table->float('min_stock', 8, 2)->nullable()->default(0);
            $table->float('max_stock', 8, 2)->nullable()->default(0);
            $table->float('ideal_stock', 8, 2)->nullable()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('component_stock', function (Blueprint $table) {
            $table->dropColumn('min_stock');
            $table->dropColumn('max_stock');
            $table->dropColumn('ideal_stock');
        });
    }
}
