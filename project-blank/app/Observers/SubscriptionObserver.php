<?php

namespace App\Observers;

use App\Subscription;
use App\Traits\LocaleHelper;
use Log;

class SubscriptionObserver
{
  use LocaleHelper;

  /**
   * Handle the Subscription "created" event.
   *
   * @param  \App\Subscription  $subscription
   * @return void
   */
  public function created(Subscription $subscription)
  {
    //
  }

  /**
   * Handle the Subscription "updated" event.
   *
   * @param  \App\Subscription  $subscription
   * @return void
   */
  public function updated(Subscription $subscription)
  {
    //
  }

  /**
   * Handle the Subscription "deleted" event.
   *
   * @param  \App\Subscription  $subscription
   * @return void
   */
  public function deleted(Subscription $subscription)
  {
    //
  }

  /**
   * Handle the Subscription "forceDeleted" event.
   *
   * @param  \App\Subscription  $subscription
   * @return void
   */
  public function forceDeleted(Subscription $subscription)
  {
    //
  }
}
