<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\AdminCompany;
use Log;

class AdminCompanyEmailVerification extends Mailable
{
    use Queueable, SerializesModels;

    public $admin;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(AdminCompany $admin)
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
        return $this->subject('Activación de Cuenta')->markdown('emails.admin.verifyAdminCompany')->with([
            'name'=>$this->admin ? $this->admin->name : '',
            'email'=>$this->admin->email,
            'token'=>$this->admin->activation_token,
            'company'=>$this->admin->company ? $this->admin->company->name : '',
        ]);
    }
}
