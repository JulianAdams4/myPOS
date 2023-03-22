<?php

namespace App\Listeners;

use App\Events\CustomerCreatedEvent;
use App\Mail\CustomerEmailVerification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Log;

class SendCustomerVerificationEmail
{
	/**
	 * Create the event listener.
	 *
	 * @return void
	 */
	public function __construct()
	{
		//
	}

	/**
	 * Handle the event.
	 *
	 * @param  CustomerCreatedEvent  $event
	 * @return void
	 */
	public function handle(CustomerCreatedEvent $event)
	{
		if($event->customer->verification_token){
			try {
				Mail::to($event->customer->user->email)->send(new CustomerEmailVerification($event->customer));            
			} catch (\Exception $e) {
				Log::info('ERROR: no se pudo enviar email de verificacion '.$e);
			}
		}
	}
}
