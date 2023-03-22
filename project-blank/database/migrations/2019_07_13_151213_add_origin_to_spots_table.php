<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOriginToSpotsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Origen
        // 0: Normal
        // 1: Dividir cuentas
        // 2: Uber eats
        // 3: Rappi
        // 4: Postmates
        // 5: Sin delantal
        // 6: Domicilios.com
        // 7: iFood
        Schema::table('spots', function (Blueprint $table) {
            $table->tinyInteger('origin')->default('1');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
}
