<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCashierBalanceXReportsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cashier_balance_x_reports', function (Blueprint $table) {
            $table->increments('id');
            $table->text('x_cashier_number_day')->nullable();
            $table->unsignedInteger('cashier_balance_id');
            $table->foreign('cashier_balance_id')->references('id')->on('cashier_balances')->onDelete('cascade');
            $table->unsignedInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->json('order_ids');
            $table->date('date_close');
            $table->time('hour_close');
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
        Schema::dropIfExists('cashier_balance_x_reports');
    }
}
