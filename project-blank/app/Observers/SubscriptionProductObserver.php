<?php

namespace App\Observers;

use App\SubscriptionProduct;

class SubscriptionProductObserver
{
  /**
   * Handle the SubscriptionProduct "created" event.
   *
   * @param  \App\SubscriptionProduct  $subscriptionProduct
   * @return void
   */
  public function created(SubscriptionProduct $subscriptionProduct)
  {
    //
  }

  /**
   * Handle the SubscriptionProduct "updated" event.
   *
   * @param  \App\SubscriptionProduct  $subscriptionProduct
   * @return void
   */
  public function updated(SubscriptionProduct $subscriptionProduct)
  {
    //
  }

  /**
   * Handle the SubscriptionProduct "deleted" event.
   *
   * @param  \App\SubscriptionProduct  $subscriptionProduct
   * @return void
   */
  public function deleted(SubscriptionProduct $subscriptionProduct)
  {
    //
  }

  /**
   * Handle the SubscriptionProduct "forceDeleted" event.
   *
   * @param  \App\SubscriptionProduct  $subscriptionProduct
   * @return void
   */
  public function forceDeleted(SubscriptionProduct $subscriptionProduct)
  {
    //
  }
}
