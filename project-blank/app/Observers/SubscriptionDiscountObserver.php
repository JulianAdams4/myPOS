<?php

namespace App\Observers;

use App\SubscriptionDiscount;
use App\Traits\LocaleHelper;
use Log;

class SubscriptionDiscountObserver
{
  use LocaleHelper;

  /**
   * Handle the SubscriptionDiscount "created" event.
   *
   * @param  \App\SubscriptionDiscount  $subscriptionDiscount
   * @return void
   */
  public function created(SubscriptionDiscount $subscriptionDiscount)
  {
    //
  }

  /**
   * Handle the SubscriptionDiscount "updated" event.
   *
   * @param  \App\SubscriptionDiscount  $subscriptionDiscount
   * @return void
   */
  public function updated(SubscriptionDiscount $subscriptionDiscount)
  {
    //
  }

  /**
   * Handle the SubscriptionDiscount "deleted" event.
   *
   * @param  \App\SubscriptionDiscount  $subscriptionDiscount
   * @return void
   */
  public function deleted(SubscriptionDiscount $subscriptionDiscount)
  {
    //
  }

  /**
   * Handle the SubscriptionDiscount "forceDeleted" event.
   *
   * @param  \App\SubscriptionDiscount  $subscriptionDiscount
   * @return void
   */
  public function forceDeleted(SubscriptionDiscount $subscriptionDiscount)
  {
    //
  }
}
