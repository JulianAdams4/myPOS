<?php

use Faker\Generator as Faker;

$factory->define(App\AdminStore::class, function (Faker $faker) {
    $store = factory(App\Store::class)->create();
    return [
        'store_id' => $store->id,
        'name' => $store->name,
        'email' => $faker->email,
        'password' => bcrypt('123456'),
        'api_token' => str_random(60),
        'activation_token' => str_random(60),
        'active' => 1,
        'email_verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];
});
