<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    protected $hidden = [
        'updated_at',
        'company_id'
    ];

    protected $casts = [
        'value' => 'float'
    ];

    public function order()
    {
        return $this->belongsTo('App\Order');
    }
    
    public function getFormattedNoteNumber()
    {
        $numberLength = 5;
        $numberStr = (string) $this->credit_sequence;
        $diffLength = strlen($numberStr) < $numberLength
            ? $numberLength - strlen($numberStr)
            : 0;
        for ($i = 0; $i < $diffLength; $i++) {
            $numberStr = "0" . $numberStr;
        }
        return $numberStr;
    }
}
