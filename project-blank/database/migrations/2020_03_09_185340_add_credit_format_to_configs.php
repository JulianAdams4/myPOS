<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\StoreConfig;

class AddCreditFormatToConfigs extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->json('credit_format');
        });

        StoreConfig::chunk(200, function ($configs) {
            foreach ($configs as $config) {
                $config->credit_format = '[{"cmd": "ALIGN", "payload": {"alignment": "CENTER"}}, {"cmd": "PRINT_TEXT", "payload": {"text": "%s", "tokens": ["store_name"]}}, {"cmd": "ALIGN", "payload": {"alignment": "LEFT"}}, {"cmd": "PRINT_TEXT", "payload": {"text": "Fecha: %s", "tokens": ["invoice_created_at"]}}, {"cmd": "PRINT_TEXT", "payload": {"text": "Cédula/RUC: %s", "tokens": ["invoice_document"]}}, {"cmd": "PRINT_TEXT", "payload": {"text": "Nombre: %s", "tokens": ["invoice_name"]}}, {"cmd": "PRINT_TEXT", "payload": {"text": "Cliente: %s", "tokens": ["customer_name"], "from_integration": true}}, {"cmd": "PRINT_TEXT", "payload": {"text": "Dirección: %s", "tokens": ["invoice_address"]}}, {"cmd": "PRINT_TEXT", "payload": {"text": "Teléfono: %s", "tokens": ["invoice_phone"]}}, {"cmd": "PRINT_TEXT", "payload": {"text": "E-mail: %s", "tokens": ["invoice_email"]}}, {"cmd": "PRINT_TEXT", "payload": {"text": "Mesero: %s", "tokens": ["order_employee_name"]}}, {"cmd": "PRINT_TEXT", "payload": {"text": "Nota de Crédito #%s", "tokens": ["credit_note_number"]}}, {"cmd": "PRINT_TEXT", "payload": {"text": "%s", "tokens": ["spot_name"]}}, {"cmd": "PRINT_TEXT", "payload": {"text": "Orden %s: %s", "tokens": ["integration_name", "order_number"], "from_integration": true}}, {"cmd": "PRINT_PRODUCTS_HEADER", "payload": {"show_reprint": false, "show_header_row": true}}, {"cmd": "PRINT_PRODUCTS", "payload": {"is_food_service": false, "show_instructions": true}}, {"cmd": "ALIGN", "payload": {"alignment": "CENTER"}}, {"cmd": "PRINT_TEXT", "payload": {"text": "------------------------------------"}}, {"cmd": "ALIGN", "payload": {"alignment": "RIGHT"}}, {"cmd": "PRINT_PRICE_SUMMARY", "payload": {"text": ""}}, {"cmd": "ALIGN", "payload": {"alignment": "CENTER"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "PRINT_PAYMENT_METHOD", "payload": {"text": ""}}, {"cmd": "PRINT_TEXT", "payload": {"text": "%s", "tokens": ["billing_code_resolution"]}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "PRINT_TEXT", "payload": {"text": "%s", "tokens": ["tax_billing_description"]}}, {"cmd": "ALIGN", "payload": {"alignment": "CENTER"}}, {"cmd": "PRINT_TEXT", "payload": {"text": "------------------------------------"}}, {"cmd": "ALIGN", "payload": {"alignment": "LEFT"}}, {"cmd": "FEED", "payload": {"lines": 2}}, {"cmd": "CUT", "payload": {"mode": "FULL"}}, {"cmd": "PULSE", "payload": {"mode": ""}}]';
                $config->save();
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
        Schema::table('store_configs', function (Blueprint $table) {
            $table->dropColumn('credit_format');
        });
    }
}
