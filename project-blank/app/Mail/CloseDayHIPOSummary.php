<?php

namespace App\Mail;

use App\ComponentStock;
use App\Traits\TimezoneHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class CloseDayHIPOSummary extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $store;
    public $data;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($store, $data)
    {
        $this->store = $store;
        $this->data = $data;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $exp_c = 0;
        $ext_c = 0;
        $lowst_c = 0;
        $expiration_c = 0;
        $total_expenses = 0;
        $revoked_orders = 0;
        $hour_close = TimezoneHelper::localizedNowDateForStore($this->store)->format('H:i:s');

        $max_expiration_date = TimezoneHelper::localizedNowDateForStore($this->store)->addDays(7);

        $today = TimezoneHelper::localizedNowDateForStore($this->store);
        $fechaApertura = $today;
        if(isset($this->data['date_open'])){
            $fechaApertura = TimezoneHelper::convertToServerDateTime($this->data['date_open']." ".$this->data['hour_open'], $this->store);
        }

        if ($this->data['expenses']) {
            $exp_c = 1;
        }
        if ($this->data['externalValues']) {
            $ext_c = 1;
        }
        if ($this->data['revoked_orders']) {
            $revoked_orders = $this->data['revoked_orders'];
        }
        if ($this->data['hour_close']) {
            #Validar que exista una hora de salida
            $hour_close = $this->data['hour_close'];
        }
        foreach ($this->data['expenses'] as $xp) {
            $total_expenses += $xp['value'];
        }
        $total_currency = $this->data['value_cash'] + $this->data['value_open'];

        $low_stock_items = ComponentStock::select(
            'components.name',
            'component_stock.stock',
            'component_stock.alert_stock'
        )->join('components', 'components.id', '=', 'component_stock.component_id')
        ->where('component_stock.store_id', $this->store->id)
        ->where('components.status', 1)
        ->whereRaw("component_stock.stock <= component_stock.alert_stock")
        ->get();

        $expiration_items = ComponentStock::select(
            'components.name',
            DB::raw("DATEDIFF('$max_expiration_date', stock_movements.expiration_date) AS exp_days")
        )->join('components', 'components.id', '=', 'component_stock.component_id')
        ->join(
            'stock_movements',
            'stock_movements.component_stock_id',
            '=',
            'component_stock.id'
        )->where('component_stock.store_id', $this->store->id)
        ->where('components.status', 1)
        ->whereRaw("DATE(stock_movements.expiration_date) < '$max_expiration_date'")
        ->get();

        if ($low_stock_items) {
            $lowst_c = 1;
        }
        if ($expiration_items) {
            $expiration_c = 1;
        }

        $config = [
            'exp_c' => $exp_c,
            'ext_c' => $ext_c,
            'lowst_c' => $lowst_c,
            'expiration_c' => $expiration_c,
            'total_expenses' => ($total_expenses / 100),
            'total_currency' => $total_currency,
            'today' => $today->format('Y-m-d'),
            'hour_open' => $this->data['hour_open'],
            'hour_close' => $hour_close,
            'revoked_orders' => $revoked_orders,
            'reported_close' => $this->data['reported_value_close'],
            'date_open' => $fechaApertura->format('Y-m-d')
        ];

        return $this->subject($this->store->name . " " . $today->format('Y-m-d'))
                ->view('maileclipse::templates.hIPOCloseDay')
                ->with('config', $config)
                ->with('expenses', $this->data['expenses'])
                ->with('externals', $this->data['externalValues'])
                ->with('low_stock_items', $low_stock_items)
                ->with('expiration_items', $expiration_items)
                ->with('uber_discount', $this->data['uber_discount']);
    }
}
