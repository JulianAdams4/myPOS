<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTriggerOrderIdsToDynamicPricingRuleTimelinesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('dynamic_pricing_rule_timelines', function (Blueprint $table) {
            $table->json('trigger_order_ids');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('dynamic_pricing_rule_timelines', function (Blueprint $table) {
            $table->dropColumn('trigger_order_ids');
        });
    }
}
