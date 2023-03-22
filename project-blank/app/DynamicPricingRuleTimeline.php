<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class DynamicPricingRuleTimeline extends Model
{
    protected $casts = [
        'rule' => 'array',
        'order_ids' => 'array',
        'product_ids' => 'array',
        'trigger_order_ids' => 'array'
    ];

    public function dynamicPricingRule()
    {
        return $this->belongsTo('App\DynamicPricingRule', 'rule_id')->withTrashed();
    }
}
