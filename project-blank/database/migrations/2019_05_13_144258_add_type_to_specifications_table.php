<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTypeToSpecificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Tipos
        // 1: Normal
        // 2: Tamaño     
        Schema::table('specification_categories', function (Blueprint $table) {
            $table->unsignedTinyInteger('type')->default(1);       
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('specification_categories', function (Blueprint $table) {
            $table->dropColumn("type");            
        });
    }
}
