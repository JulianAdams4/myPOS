<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\StoreConfig;
use App\Traits\TimezoneHelper;

class BlindInventory extends Mailable
{
    use Queueable, SerializesModels;
    use Queueable, SerializesModels;

    public $store;
    public $data;
    public $actual_date;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($store, $data)
    {
        $this->store = $store;
        $this->data = $data;
        TimezoneHelper::localizedNowDateForStore($store)->toDateTimeString();
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $storeConfig = StoreConfig::where('store_id', $this->store->id)->first();
        $today = Carbon::today();

        return $this->subject("INVENTARIO CIEGO " . $this->actual_date)
                ->view('maileclipse::templates.BlindInventory')
                ->with('data', $this->data)
                ->with('today', $this->actual_date)
                ->with('store', $storeConfig);
    }
}
