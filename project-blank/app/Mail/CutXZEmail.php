<?php

namespace App\Mail;

use Log;
use Carbon\Carbon;
use App\StoreConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class CutXZEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $store;
    public $data;
    public $extraData;
    public $type;
    public $employee;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($store, $data, $extraData, $type, $employee)
    {
        $this->store = $store;
        $this->data = $data;
        $this->extraData = $extraData;
        $this->type = $type;
        $this->employee = $employee;
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

        $hasTaxValues = false;
        if (count($this->extraData['tax_values_details']) > 0) {
            $hasTaxValues = true;
        }

        return $this->subject("CORTE Z " . $this->store->name . " " . $today->format('d-m-Y'))
                ->view('maileclipse::templates.CutXZ')
                ->with('data', $this->data)
                ->with('type', $this->type)
                ->with('has_tax_values', $hasTaxValues)
                ->with('extra_data', $this->extraData)
                ->with('conversion', $storeConfig->dollar_conversion)
                ->with('today', $today->format('d-m-Y'))
                ->with('employee_name', $this->employee->name);
    }
}
