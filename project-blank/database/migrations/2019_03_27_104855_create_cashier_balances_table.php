<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCashierBalancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cashier_balances', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('employee_id_open');
            $table->foreign('employee_id_open')->references('id')->on('employees')->onDelete('cascade');
            $table->unsignedInteger('employee_id_close')->nullable();
            $table->foreign('employee_id_close')->references('id')->on('employees')->onDelete('cascade');
            $table->date('date_open');
            $table->time('hour_open');
            $table->date('date_close')->nullable();
            $table->time('hour_close')->nullable();
            $table->unsignedInteger('value_previous_close');
            $table->unsignedInteger('value_open');
            $table->unsignedInteger('value_close')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('cashier_balances');
    }
}
