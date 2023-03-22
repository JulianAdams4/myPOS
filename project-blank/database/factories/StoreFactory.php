<?php

use Faker\Generator as Faker;

$factory->define(App\Store::class, function (Faker $faker) {
    $company = factory(App\Company::class)->create();
    $city = factory(App\City::class)->create();
    return [
        'company_id' => $company->id,
        'address' => $company->address,
        'name' => $company->name,
        'phone' => $company->phone,
        'contact' => $company->contact,
        'currency' => 'USD',
        'issuance_point' => '002',
        'code' => '001',
        'country_code' => 'EC',
        'bill_sequence' => 58,
        'order_app_sync' => 1,
        'button_bill_prints' => 3,
        'city_id' => $city->id,
        'max_sequence' => 1,
        'password' => bcrypt('123456'),
        'remember_token' => str_random(10),
    ];
});
