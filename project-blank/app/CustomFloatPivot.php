<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\Pivot;

class CustomFloatPivot extends Pivot
{
    protected $casts = [
        'value' => 'float'
    ];
}