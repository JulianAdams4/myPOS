<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

use App\Employee;
use App\MailRecipient;

class CreateMailRecipientsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mail_recipients', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedInteger('store_id')->unsigned();
            $table->foreign('store_id')->references('id')->on('stores');

            $table->string('email');

            $table->unique(['store_id', 'email']);
        });

        Employee::where('type_employee', 1)->chunk(50, function ($employees) {
            foreach ($employees as $employee) {
                $mailRecipient = new MailRecipient();
                $mailRecipient->store_id = $employee->store_id;
                $mailRecipient->email = $employee->email;
                $mailRecipient->save();
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
        Schema::dropIfExists('mail_recipients');
    }
}
