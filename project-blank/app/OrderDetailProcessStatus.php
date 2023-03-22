<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class OrderDetailProcessStatus extends Model
{
    protected $fillable = [
        'process_status'
    ];
    
    const NONE = 0;
    const CREATED = 1;
    const PRINTED = 2;
    const DISPATCHED = 4;

    //
    public function isDispatched()
    {
        return $this->process_status == OrderDetailProcessStatus::DISPATCHED;
    }
}
