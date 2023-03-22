<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Events\AdminCompanyCreatedEvent;

class AdminCompany extends Authenticatable
{
	use Notifiable;

    protected $guard = 'company';

	protected $fillable = [
		'company_id', 'name', 'email', 'password', 'api_token', 'active', 'activation_token',
	];

	protected $hidden = [
		'password', 'activation_token',
	];

	protected $dispatchesEvents = [
		'created' => AdminCompanyCreatedEvent::class,
	];

	public function company()
	{
		return $this->belongsTo('App\Company');
	}
	
}
