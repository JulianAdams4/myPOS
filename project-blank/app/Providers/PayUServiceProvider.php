<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Lib\PayU;

class PayUServiceProvider extends ServiceProvider
{
  public function boot()
  {
  }

  public function register()
  {
    $this->app->singleton('PayU', new PayU());
  }
}
