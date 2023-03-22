<?php

use App\Company;
use App\StripeCustomerCompany;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RefreshStripeCustomerCompaniesForProductionTokens extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        if (config('app.env') != 'production') {
            return;
        }

        StripeCustomerCompany::truncate();

        Company::chunk(200, function ($companies) {
            foreach ($companies as $company) {
                $stripeCustomer = \Stripe\Customer::create([
                    'name' => $company->name,
                    'email' => $company->email
                ]);

                $stripeCompany =  StripeCustomerCompany::create([
                    'company_id' => $company->id,
                    'stripe_customer_id' => $stripeCustomer->id
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    }
}
