<?php

use App\Order;
use App\Payment;
use App\PaymentType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

class CreatePaymentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();
            $table->decimal('total', 14, 4);
            $table->integer('type');
            $table->string('card_last_digits', 4)->nullable();

            $table->unsignedInteger('order_id')->nullable();
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->unsignedInteger('card_id')->nullable();
            $table->foreign('card_id')->references('id')->on('cards')->onDelete('cascade');
        });

        Order::chunk(200, function ($orders) {
            foreach ($orders as $order) {
                if ($order->value_cash > 0) {
                    $payment = $this->createPayment($order);
                    $payment->total = $order->value_cash;
                    $payment->type = PaymentType::CASH;
                    $payment->save();
                }
                if ($order->value_debit_card > 0) {
                    $payment = $this->createPayment($order);
                    $payment->total = $order->value_debit_card;
                    $payment->type = PaymentType::DEBIT;
                    $payment->save();
                }
                if ($order->value_credit_card > 0) {
                    $payment = $this->createPayment($order);
                    $payment->total = $order->value_credit_card;
                    $payment->type = PaymentType::CREDIT;
                    $payment->save();
                }
                if ($order->value_transfer > 0) {
                    $payment = $this->createPayment($order);
                    $payment->total = $order->value_transfer;
                    $payment->type = PaymentType::TRANSFER;
                    $payment->save();
                }
                if ($order->value_rappi_pay > 0) {
                    $payment = $this->createPayment($order);
                    $payment->total = $order->value_rappi_pay;
                    $payment->type = PaymentType::RAPPI_PAY;
                    $payment->save();
                }
                if ($order->value_others > 0) {
                    $payment = $this->createPayment($order);
                    $payment->total = $order->value_others;
                    $payment->type = PaymentType::OTHER;
                    $payment->save();
                }
            }
        });
    }

    public function createPayment(Order $order)
    {
        $payment = new Payment();
        $payment->total = $order->total;
        $payment->card_last_digits = $order->card_last_digits;
        $payment->card_id = $order->card_id;
        $payment->order_id = $order->id;
        $payment->created_at = $order->created_at;
        $payment->updated_at = $order->updated_at;

        return $payment;
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('payments');
    }
}
