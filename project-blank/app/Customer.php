<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
	use SoftDeletes;

	protected $fillable = [
		'name', 'last_name', 'phone', 'email'
	];

	protected $hidden = [
		'created_at', 'updated_at'
	];

	public function addresses()
	{
		return $this->hasMany('App\CustomerAddress');
	}

	public function getFullName()
	{
		return $this->name . " " . $this->last_name;
	}
}
