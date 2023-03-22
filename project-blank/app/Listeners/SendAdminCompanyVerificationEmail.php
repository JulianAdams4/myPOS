<?php

namespace App\Listeners;

use App\Events\AdminCompanyCreatedEvent;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Mail\AdminCompanyEmailVerification;
use Illuminate\Support\Facades\Mail;
use Log;

class SendAdminCompanyVerificationEmail
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
	 * @param  AdminCompanyCreatedEvent  $event
	 * @return void
	 */
	public function handle(AdminCompanyCreatedEvent $event)
	{
		try {
			Mail::to($event->admin->email)->send(new AdminCompanyEmailVerification($event->admin));            
		} catch (\Exception $e) {
			Log::info('ERROR: no se pudo enviar email de verificacion '.$e);
		}
	}
}
