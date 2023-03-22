<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DynamicPricingRule extends Model
{
    use SoftDeletes;

    protected $casts = [
        'rule' => 'array',
        'active_deliveries' => 'array'
    ];

    public function timelines()
    {
        return $this->hasMany('App\DynamicPricingRuleTimeline', 'rule_id');
    }
}
