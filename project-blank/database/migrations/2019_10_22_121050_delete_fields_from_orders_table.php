<?php

use App\Order;
use App\Payment;
use App\PaymentType;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class DeleteFieldsFromOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('value_cash');
            $table->dropColumn('value_debit_card');
            $table->dropColumn('value_credit_card');
            $table->dropColumn('value_transfer');
            $table->dropColumn('value_rappi_pay');
            $table->dropColumn('value_others');
            $table->dropColumn('card_last_digits');
            $table->dropForeign('orders_card_id_foreign');
            $table->dropColumn('card_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('value_cash', 14, 4)->nullable();
            $table->decimal('value_debit_card', 14, 4)->nullable();
            $table->decimal('value_credit_card', 14, 4)->nullable();
            $table->decimal('value_transfer', 14, 4)->nullable();
            $table->decimal('value_rappi_pay', 14, 4)->nullable();
            $table->decimal('value_others', 14, 4)->nullable();
            $table->string('card_last_digits', 4)->nullable();
            $table->unsignedInteger('card_id')->nullable();
            $table->foreign('card_id')->references('id')->on('cards')->onDelete('cascade');
        });

        Payment::chunk(200, function ($payments) {
            foreach ($payments as $payment) {
                $order = Order::find($payment->order_id);
                $order->card_last_digits = $payment->card_last_digits;
                $order->card_id = $payment->card_id;

                switch ($payment->type) {
                    case PaymentType::CASH:
                        $order->value_cash = $payment->total;
                        break;
                    case PaymentType::DEBIT:
                        $order->value_debit_card = $payment->total;
                        break;
                    case PaymentType::CREDIT:
                        $order->value_credit_card = $payment->total;
                        break;
                    case PaymentType::TRANSFER:
                        $order->value_transfer = $payment->total;
                        break;
                    case PaymentType::RAPPI_PAY:
                        $order->value_rappi_pay = $payment->total;
                        break;
                    case PaymentType::OTHER:
                        $order->value_others = $payment->total;
                        break;
                }

                $order->save();
            }
        });
    }
}
