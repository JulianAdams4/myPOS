<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateGoalsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('goals', function (Blueprint $table) {
			$table->increments('id');
			$table->dateTime('start_date')->nullable(false);
			$table->dateTime('end_date')->nullable(false);
			$table->integer('value')->nullable(false);
			$table->unsignedInteger('goal_type_id')->nullable(false);
			$table->foreign('goal_type_id')->references('id')->on('goal_types');
			$table->string('scope')->nullable(false);
			$table->unsignedInteger('status')->default(1);
			$table->unsignedInteger('store_id')->nullable();
			$table->foreign('store_id')->references('id')->on('stores');
			$table->unsignedInteger('employee_id')->nullable();
			$table->foreign('employee_id')->references('id')->on('employees');
			$table->unsignedInteger('product_category_id')->nullable();
			$table->foreign('product_category_id')->references('id')->on('product_categories');
			$table->unsignedInteger('product_id')->nullable();
			$table->foreign('product_id')->references('id')->on('products');
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
        Schema::dropIfExists('goals');
    }
}
