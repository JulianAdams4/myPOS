<?php

use App\User;
use App\Role;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdministratorTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $role = Role::where('name', Role::ADMIN)->first();
        $email = 'xxx@xxx.xxx';
        DB::table('users')->insert([
            'role_id' => $role->id,
            'name' => 'Admin',
            'email' => $email,
            'email_verified_at' => Carbon::now()->toDateTimeString(),
            'password' => Hash::make('123456'),
            'api_token' => str_random(60),
            'active' => 1,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }
}
