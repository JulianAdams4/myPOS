<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateDynamicPricingRuleTimelinesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('dynamic_pricing_rule_timelines', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('rule_id');
            $table->foreign('rule_id')->references('id')->on('dynamic_pricing_rules')->onDelete('cascade');
            $table->dateTime('enabled_date');
            $table->dateTime('approximate_disabled_date');
            $table->dateTime('disabled_date')->nullable();
            $table->json('rule');
            $table->json('order_ids')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('dynamic_pricing_rule_timelines');
    }
}
