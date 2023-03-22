<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeSizeColumnsComponentVariationComponents extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement('ALTER TABLE component_variation_components MODIFY COLUMN consumption FLOAT(10,4),
        MODIFY COLUMN value_reference FLOAT(10,4)');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('ALTER TABLE component_variation_components MODIFY COLUMN consumption FLOAT(8,2),
        MODIFY COLUMN value_reference FLOAT(8,2)');
    }
}
