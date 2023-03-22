<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddProductionCosts extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('product_details', function (Blueprint $table) {
            $table->decimal('production_cost', 14, 4)->default(0);
            $table->decimal('income', 14, 4)->default(0);
            $table->decimal('cost_ratio', 14, 4)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('product_details', function (Blueprint $table) {
            $table->dropColumn('production_cost');
            $table->dropColumn('income');
            $table->dropColumn('cost_ratio');
        });
    }
}
