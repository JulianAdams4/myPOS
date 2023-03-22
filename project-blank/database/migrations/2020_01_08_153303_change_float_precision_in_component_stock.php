<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeFloatPrecisionInComponentStock extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('initial_stock', 14, 4)->change();
            $table->decimal('value', 14, 4)->change();
            $table->decimal('final_stock', 14, 4)->change();
            $table->decimal('cost', 14, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->float('initial_stock', 8, 2)->change();
            $table->float('value', 8, 2)->change();
            $table->float('final_stock', 8, 2)->change();
            $table->float('cost', 8, 2)->change();
        });
    }
}
