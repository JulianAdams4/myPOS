<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InventoryAction extends Model
{
    protected $fillable = [
        'name', 'action', 'code'
    ];
}
