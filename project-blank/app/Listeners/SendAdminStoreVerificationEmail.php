<?php

namespace App\Listeners;

use App\Events\AdminStoreCreatedEvent;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Mail\AdminStoreEmailVerification;
use Illuminate\Support\Facades\Mail;
use Log;

class SendAdminStoreVerificationEmail
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
	 * @param  AdminStoreCreatedEvent  $event
	 * @return void
	 */
	public function handle(AdminStoreCreatedEvent $event)
	{
		try {
			Mail::to($event->admin->email)->send(new AdminStoreEmailVerification($event->admin));            
		} catch (\Exception $e) {
			Log::info('ERROR: no se pudo enviar email de verificacion '.$e);
		}
	}
}
