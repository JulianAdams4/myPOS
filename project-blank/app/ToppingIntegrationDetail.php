<?php

namespace App;

use App\Helper;
use Illuminate\Database\Eloquent\Model;

class ToppingIntegrationDetail extends Model{
    public function specification(){
        return $this->belongsTo('App\Specification', 'specification_id');
    }

}
