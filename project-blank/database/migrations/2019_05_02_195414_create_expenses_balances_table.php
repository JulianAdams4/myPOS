<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateExpensesBalancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('expenses_balances', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('cashier_balance_id');
            $table->foreign('cashier_balance_id')->references('id')->on('cashier_balances')->onDelete('cascade');
            $table->string('name');
            $table->unsignedInteger('value');
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
        Schema::dropIfExists('expenses_balances');
    }
}
