<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProductionOrderStatusesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('production_order_statuses', function (Blueprint $table) {
            $table->increments('id');
            $table->enum('status', ['created', 'in_process', 'finished', 'cancelled']);
            $table->unsignedInteger('production_order_id');
            $table->foreign('production_order_id')
                ->references('id')->on('production_orders')->onDelete('cascade');
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')
                ->references('id')->on('users')->onDelete('cascade');
            $table->unsignedInteger('reason_id')->nullable();
            $table->foreign('reason_id')
                ->references('id')->on('production_order_reasons')->onDelete('cascade');
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
        Schema::dropIfExists('production_order_statuses');
    }
}
