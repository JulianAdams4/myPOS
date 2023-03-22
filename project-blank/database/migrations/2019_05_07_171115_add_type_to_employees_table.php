<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTypeToEmployeesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Tipos
        // 1: Admin Store
        // 2: Despacho
        // 3: Cajero
        // 4: Mesero
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedTinyInteger('type_employee')->default(3);          
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn("type_employee");                        
        });
    }
}
