<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrderDetailProcessStatusesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Estados
        // 1: Tomada la orden
        // 2: Mandar a cocina
        // 3: En preparación
        // 4: Despachado
        // 5: Pagado
        Schema::create('order_detail_process_statuses', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedTinyInteger('process_status');
            $table->unsignedInteger('order_detail_id');
            $table->foreign('order_detail_id')->references('id')->on('order_details')->onDelete('cascade');
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
        Schema::dropIfExists('order_detail_process_statuses');
    }
}
