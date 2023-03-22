<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Customer;

class CustomerEmailVerification extends Mailable
{
	use Queueable, SerializesModels;

	public $customer;

	/**
	 * Create a new message instance.
	 *
	 * @return void
	 */
	public function __construct(Customer $customer)
	{
		$this->customer = $customer;
	}

	/**
	 * Build the message.
	 *
	 * @return $this
	 */
	public function build()
	{
		return $this->subject('Activación de Cuenta')->markdown('emails.customer.verifyCustomer')->with([
			'name'=>$this->customer->user ? $this->customer->user->name : '',
			'email'=>$this->customer->user ? $this->customer->user->email : '',
			'token'=>$this->customer->verification_token,
		]);
	}
}
