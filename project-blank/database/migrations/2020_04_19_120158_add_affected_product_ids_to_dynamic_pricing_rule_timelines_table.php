<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddAffectedProductIdsToDynamicPricingRuleTimelinesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('dynamic_pricing_rule_timelines', function (Blueprint $table) {
            $table->json('product_ids')->nullable();
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
            $table->dropColumn('product_ids');
        });
    }
}
