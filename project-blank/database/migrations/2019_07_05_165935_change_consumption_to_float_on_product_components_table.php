<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ChangeConsumptionToFloatOnProductComponentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement('ALTER TABLE product_components CHANGE COLUMN consumption consumption DOUBLE(8,6) NULL DEFAULT NULL;');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('ALTER TABLE product_components CHANGE COLUMN consumption consumption DOUBLE(8,2) NULL DEFAULT NULL;');
    }
}
