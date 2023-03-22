<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StripeCustomerCompany extends Model
{
  use SoftDeletes;

  protected $fillable = ['company_id', 'stripe_customer_id'];
}
