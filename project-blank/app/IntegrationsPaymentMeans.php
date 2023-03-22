<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class IntegrationsPaymentMeans extends Model
{
    public $fillable = ['external_payment_mean_code'];
}
