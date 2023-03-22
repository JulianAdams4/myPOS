<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BlindCountMovement extends Model
{
    protected $fillable = [
        'store_id',
        'value',
        'cost'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function blindcount()
    {
        return $this->belongsTo('App\BlindCount', 'blind_count_id');
    }

    public function stockmovements()
    {
        return $this->belongsTo('App\StockMovement', 'stock_movement_id');
    }
}
