<?php

use App\Company;
use App\StripeCustomerCompany;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

// \Stripe\Stripe::setApiKey("sk_test_lbWWDd2goWSEXSSeLOD6CrLL00MInE6TrQ");

class CreateStripeCustomerCompaniesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('stripe_customer_companies', function (Blueprint $table) {
            //
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->string('stripe_customer_id');
            $table->string('is_autobilling_active')->boolean()->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

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
        //
        Schema::dropIfExists('stripe_customer_companies');
    }
}
