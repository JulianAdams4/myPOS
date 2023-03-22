<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\AdminStore;
use Log;

class AdminStoreEmailVerification extends Mailable
{
	use Queueable, SerializesModels;

	public $admin;

	/**
	 * Create a new message instance.
	 *
	 * @return void
	 */
	public function __construct(AdminStore $admin)
	{
		$this->admin = $admin;
	}

	/**
	 * Build the message.
	 *
	 * @return $this
	 */
	public function build()
	{
		return $this->subject('Activación de Cuenta')->markdown('emails.admin.verifyAdminStore')->with([
			'name'=>$this->admin->name,
			'email'=>$this->admin->email,
			'token'=>$this->admin->activation_token,
			'store'=>$this->admin->store ? $this->admin->store->name : '',
		]);
	}
}
