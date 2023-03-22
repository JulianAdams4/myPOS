<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    const PENDING  = 0;
    const ACCEPTED = 1;
    const EDITED   = 2;
    const FAILED   = 3;

    public function destinationStore()
    {
        return $this->belongsTo('App\Store', 'destination_store_id', 'id');
    }

    public function originStore()
    {
        return $this->belongsTo('App\Store', 'origin_store_id', 'id');
    }

    public function processedBy()
    {
        return $this->belongsTo('App\AdminStore', 'process_by_id', 'id');
    }

    public function originStock()
    {
        return $this->belongsTo('App\ComponentStock', 'origin_stock_id', 'id');
    }

    public function destinationStock()
    {
        return $this->belongsTo('App\ComponentStock', 'destination_stock_id', 'id');
    }

    public function canBeProcessed()
    {
        return ($this->status === $this::PENDING || $this->status === $this::FAILED);
    }
}
