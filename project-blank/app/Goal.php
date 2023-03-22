<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Goal extends Model
{
	/*
	 * status:
	 *   0: hidden
	 *   1: active
	 *   2: completed
	 */

	public function employee()
	{
		return $this->belongsTo('App\Employee', 'employee_id');
	}

	public function product()
	{
		return $this->belongsTo('App\Product', 'product_id');
	}

	public function store()
	{
		return $this->belongsTo('App\Store', 'store_id');
	}

	public function type()
	{
		return $this->hasOne('App\GoalType', 'goal_type_id');
	}
}
