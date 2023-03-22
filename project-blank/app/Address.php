<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
	use SoftDeletes;

	protected $fillable = [
		'address', 'reference', 'latitude', 'longitude', 'suburb', 'city', 'post_code', 'detail'
	];

	protected $hidden = [
		'created_at',
		'updated_at'
	];

	public function stores()
	{
		return $this->hasMany('App\Store');
	}

	public function getFullAddress()
	{
		$fullAddress = $this->address;

		if ($this->detail != null) {
			$fullAddress = $fullAddress . ", " . $this->detail;
		}

		if ($this->suburb != null) {
			$fullAddress = $fullAddress . ", " . $this->suburb;
		}

		if ($this->city != null) {
			$fullAddress = $fullAddress . ", " . $this->city;
		}

		if ($this->post_code != null) {
			$fullAddress = $fullAddress . ", " . $this->post_code;
		}

		if ($this->reference != null) {
			$fullAddress = $fullAddress . ", " . $this->reference;
		}

		return $fullAddress;
	}
}
