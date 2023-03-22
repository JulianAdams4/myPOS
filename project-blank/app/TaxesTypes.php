<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TaxesTypes extends Model
{
    public $fillable = ['code', 'name', 'country'];

    const TAXES_TYPE_ADD_NAMES = ['iva','impoconsumo']; //Nombres de los impuestos de tipo Cargo
    const TAXES_TYPE_DIS_NAMES = ['retefuente','reteica','reteiva']; //Nombres de los impuestos de tipo Retención
    const TAXES_TYPE_ADD_CODES = [0, 4]; //Cógidos de los impuestos de tipo Cargo
    const TAXES_TYPE_DIS_CODES = [1, 2, 3]; //Cógidos de los impuestos de tipo Retención
    const TAXES_CO = [
        ['tax_country' => 'CO', 'tax_name' => 'iva',         'tax_code' => 0, 'tax_type' => 'add'],
        ['tax_country' => 'CO', 'tax_name' => 'impoconsumo', 'tax_code' => 4, 'tax_type' => 'add'],
        ['tax_country' => 'CO', 'tax_name' => 'retefuente',  'tax_code' => 1, 'tax_type' => 'dis'],
        ['tax_country' => 'CO', 'tax_name' => 'reteica',     'tax_code' => 2, 'tax_type' => 'dis'],
        ['tax_country' => 'CO', 'tax_name' => 'reteiva',     'tax_code' => 3, 'tax_type' => 'dis'],
    ];
}
