<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CardStore extends Model
{
    protected $table = "card_store";

    public function card()
    {
        return $this->belongsTo('App\Card');
    }
}
