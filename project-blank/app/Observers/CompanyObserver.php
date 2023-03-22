<?php

namespace App\Observers;

use App\Company;
use App\StripeCustomerCompany;

class CompanyObserver
{
  /**
   * Handle the Company "created" event.
   *
   * @param  \App\Company  $company
   * @return void
   */
  public function created(Company $company)
  {
    //
    /**
     * Stripe sync
     */
    $stripeCustomer = \Stripe\Customer::create([
      'name' => $company->name,
      'email' => $company->email
    ]);

    $stripeCompany =  StripeCustomerCompany::create([
      'company_id' => $company->id,
      'stripe_customer_id' => $stripeCustomer->id
    ]);
  }

  /**
   * Handle the Company "updated" event.
   *
   * @param  \App\Company  $company
   * @return void
   */
  public function updated(Company $company)
  {
    //
    /**
     * Stripe sync
     */
    $stripeId = $company->getStripeIdAttribute();
    $stripeCustomer = \Stripe\Customer::update(
      $stripeId,
      [
        'name' => $company->name,
        'email' => $company->email
      ]
    );
  }

  /**
   * Handle the Company "deleted" event.
   *
   * @param  \App\Company  $company
   * @return void
   */
  public function deleted(Company $company)
  {
    //
    /**
     * Stripe sync
     */
    $stripeId = $company->getStripeIdAttribute();

    $customer = \Stripe\Customer::retrieve(
      $stripeId
    );

    $customer->delete();
  }

  /**
   * Handle the Company "forceDeleted" event.
   *
   * @param  \App\Company  $company
   * @return void
   */
  public function forceDeleted(Company $company)
  {
    //
    /**
     * Stripe sync
     */
    $stripeId = $company->getStripeIdAttribute();

    $customer = \Stripe\Customer::retrieve(
      $stripeId
    );

    $customer->delete();
  }
}
