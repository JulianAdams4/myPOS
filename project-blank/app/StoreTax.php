<?php

namespace App;

use App\Traits\TimezoneHelper;
use Illuminate\Database\Eloquent\Model;

class StoreTax extends Model
{
    public $fillable = ['id'];
    
    public function store()
    {
        return $this->belongsTo('App\Store');
    }
    
    public function products()
    {
        return $this->belongsToMany(
            'App\Product', // Tabla destino
            'product_taxes', // Nombre de tabla intermedia
            'store_tax_id', // ID del origen
            'product_id' // ID del destino
        );
    }

    public function getCreatedAtAttribute($value)
    {
        if ($this->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->store)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        if ($this->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->store)->toDateTimeString();
    }

    public function taxesTypes()
    {
        return $this->belongsTo('App\TaxesTypes', 'tax_type');
    }

    public function taxesIntegrations()
    {
        return $this->hasMany('App\StoreTaxesIntegrations', 'id_tax', 'id');
    }
}
