<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddActiveDeliveriesPromotionToDynamicPricingRulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('dynamic_pricing_rules', function (Blueprint $table) {
            $table->json('active_deliveries')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('dynamic_pricing_rules', function (Blueprint $table) {
            $table->dropColumn('active_deliveries');
        });
    }
}
