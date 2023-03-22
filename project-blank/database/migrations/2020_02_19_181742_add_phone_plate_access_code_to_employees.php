<?php

use App\Employee;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPhonePlateAccessCodeToEmployees extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->default('');
            $table->string('plate')->nullable()->default('');
            $table->string('pin_code')->nullable();
        });

        Employee::chunk(200, function ($employees) {
            foreach ($employees as $employee) {
                if ($employee->user == null) {
                    continue;
                }
                $employee->pin_code = $employee->user->pin_code;
                $employee->save();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('ci')->nullable()->default('');
            $table->dropColumn('passcode');
            $table->dropColumn('pin_code');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ci');
            $table->string('pin_code')->nullable();
            $table->string('passcode')->nullable();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('phone_number');
            $table->dropColumn('plate');
            $table->dropColumn('pin_code');
        });
    }
}
