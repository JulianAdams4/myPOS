<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddZeroLowerLimitToStoreConfigs extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    /**
     * 'zero_lower_limit' controla el minimo valor posible
     *  que puede tener el stock de cualquier item:
     *  - Si está activado: El stock llega hasta 0
     *    y si se sigue consumiendo, NO baja de cero
     *  - Si está desactivado: El stock puede ser negativo
     */
    public function up()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->boolean('zero_lower_limit')->default(true);
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
            $table->dropColumn('zero_lower_limit');
        });
    }
}
