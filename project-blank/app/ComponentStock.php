<?php

namespace App;

use Log;
use App\Store;
use App\StockTransfer;
use App\InventoryAction;
use App\Traits\TimezoneHelper;
use Illuminate\Database\Eloquent\Model;

class ComponentStock extends Model
{
    protected $table = 'component_stock';

    protected $fillable = [
        'component_id', 'store_id', 'stock', 'cost', 'alert_stock', 'merma'
    ];

    protected $casts = [
        'cost' => 'float'
    ];

    protected $appends = ['min_stock'];

    public function component()
    {
        return $this->belongsTo('App\Component', 'component_id');
    }

    public function store()
    {
        return $this->belongsTo('App\Store');
    }

    public function stockMovements()
    {
        return $this->hasMany('App\StockMovement', 'component_stock_id');
    }

    public function lastCost()
    {
        $ivIds = InventoryAction::whereIn('code', ['invoice_provider', 'receive_transfer', 'update_cost'])->get()->pluck('id');
        return $this->hasOne('App\StockMovement', 'component_stock_id')
            ->where("cost", "<>", 0)->whereIn('inventory_action_id', $ivIds)
            ->with("action")->latest();
    }

    public function dailyStocks()
    {
        return $this->hasMany('App\DailyStock');
    }

    public function getDayFromDate($day)
    {
        switch ($day->dayOfWeek) {
            case 0:
                return 'domingo';
            case 1:
                return 'lunes';
            case 2:
                return 'martes';
            case 3:
                return 'miercoles';
            case 4:
                return 'jueves';
            case 5:
                return 'viernes';
            case 6:
                return 'sabado';
            default:
                return 'another';
        }
    }

    // Realmente retorna el min/max stock del día
    // min_stock
    public function getMinStockAttribute()
    {
        $today = TimezoneHelper::localizedNowDateForStore($this->store);
        return $this->hasMany('App\DailyStock')->where('day', ComponentStock::getDayFromDate($today))->first() ?? 0;
    }

    public function pendingStockTransfers()
    {
        return $this->hasMany('App\StockTransfer', 'origin_stock_id', 'id')->where('status', StockTransfer::PENDING)
            ->orWhere('status', StockTransfer::FAILED);
    }
}
