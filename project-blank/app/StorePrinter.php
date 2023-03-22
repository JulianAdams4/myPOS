<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StorePrinter extends Model
{
    use SoftDeletes;

    protected $hidden = [
        'created_at', 'updated_at', 'deleted_at'
    ];

    //
    public function location()
    {
        return $this->belongsTo('App\StoreLocations');
    }

    public function getActionNameAttribute()
    {
        $action = "";
        switch ($this->actions) {
            case 1:
                $action = "Imprimir factura";
                break;
            case 2:
                $action = "Imprimir comanda";
                break;
            case 3:
                $action = "Imprimir precuenta";
                break;
            case 4:
                $action = "Imprimir cierre de caja";
                break;
            default:
                # code...
                break;
        }
        return $action;
    }
}
