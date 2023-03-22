<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductTax extends Model
{
  protected $fillable = [
    'product_id',
    'store_tax_id'
  ];
}
