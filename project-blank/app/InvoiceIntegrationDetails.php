<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InvoiceIntegrationDetails extends Model
{
    protected $fillable = ['invoice_id','integration','status'];
    public $timestamps = true;
}
