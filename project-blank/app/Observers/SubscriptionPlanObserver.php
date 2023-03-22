<?php

namespace App\Observers;

use App\SubscriptionPlan;
use App\Traits\LocaleHelper;

class SubscriptionPlanObserver
{
  use LocaleHelper;

  /**
   * Handle the SubscriptionPlan "created" event.
   *
   * @param  \App\SubscriptionPlan  $subscriptionPlan
   * @return void
   */
  public function created(SubscriptionPlan $subscriptionPlan)
  {
    //

    /**
     * Stripe sync
     */

    $country = $subscriptionPlan->subscriptionProduct->country;
    $isStripeActive = $country->is_stripe_active;
    $subscriptionProduct = $subscriptionPlan->subscriptionProduct;

    if (!$isStripeActive) return;

    $stripePlan = \Stripe\Plan::create([
      'amount' => $subscriptionPlan->subscriptionProduct->price,
      'currency' =>  strtolower($this->countryToCurrency($country->code)),
      'interval' => $subscriptionPlan->frequency,
      'product' => [
        'name' => $subscriptionProduct->name
      ],
    ]);

    $subscriptionPlan->stripe_id = $stripePlan->id;
    $subscriptionPlan->save();

    $subscriptionProduct->stripe_id = $stripePlan->product;
    $subscriptionProduct->save();
  }

  /**
   * Handle the SubscriptionPlan "updated" event.
   *
   * @param  \App\SubscriptionPlan  $subscriptionPlan
   * @return void
   */
  public function updated(SubscriptionPlan $subscriptionPlan)
  {
    //
  }

  /**
   * Handle the SubscriptionPlan "deleted" event.
   *
   * @param  \App\SubscriptionPlan  $subscriptionPlan
   * @return void
   */
  public function deleted(SubscriptionPlan $subscriptionPlan)
  {
    //
  }

  /**
   * Handle the SubscriptionPlan "forceDeleted" event.
   *
   * @param  \App\SubscriptionPlan  $subscriptionPlan
   * @return void
   */
  public function forceDeleted(SubscriptionPlan $subscriptionPlan)
  {
    //
  }
}
