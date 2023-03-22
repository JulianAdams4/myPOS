<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrderStatusesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('order_statuses', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_id');
            $table->enum('name', ['Creada','En Camino','Local','Con Pedido','Entregada','Cancelada','Cerrada']);
            $table->decimal('latitude', 10, 7)->nullable(); 
            $table->decimal('longitude', 10, 7)->nullable(); 
            $table->unsignedInteger('duration')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_statuses');
    }
}
