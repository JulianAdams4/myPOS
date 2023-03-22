<?php

namespace App\Helpers\PrintService;

use Log;
use App\Helper;
use App\OrderDetail;
use App\OrderDetailProcessStatus;
use App\PaymentType;
use App\Spot;
use App\StoreConfig;

use App\Traits\ReportHelperTrait;
use App\Traits\TimezoneHelper;
use Mike42\Escpos\Printer;

use App\Order;
use App\Store;

use App\Helpers\PrintService\Command;
use App\Helpers\PrintService\ProtocolCommand;


// @codingStandardsIgnoreLine
abstract class FormatType
{
    const START = 'start';
    const CENTER = 'center';
    const END = 'end';
    const SPACE_AROUND = 'space-around';
    const SPACE_BETWEEN = 'space-between';
}

// @codingStandardsIgnoreLine
abstract class PrintMode
{
    const FONT_A = "FONT_A";
    const FONT_B = "FONT_B";
    const EMPHASIZED = "EMPHASIZED";
    const DOUBLE_HEIGHT = "DOUBLE_HEIGHT";
    const DOUBLE_WIDTH = "DOUBLE_WIDTH";
    const UNDERLINE = "UNDERLINE";
}

// @codingStandardsIgnoreLine
abstract class PrintAlignment
{
    const LEFT = "LEFT";
    const CENTER = "CENTER";
    const RIGHT = "RIGHT";
}

// @codingStandardsIgnoreLine
abstract class PrintCut
{
    const FULL = "FULL";
    const PARTIAL = "PARTIAL";
}

class PrintJobHelper
{
    use ReportHelperTrait;

    public static function parseAlignment($alignment)
    {
        switch ($alignment) {
            case PrintAlignment::LEFT:
                return Printer::JUSTIFY_LEFT;
                break;
            case PrintAlignment::CENTER:
                return Printer::JUSTIFY_CENTER;
                break;
            case PrintAlignment::RIGHT:
                return Printer::JUSTIFY_RIGHT;
                break;
        }
    }

    public static function handlePrintCut($payload, &$job)
    {
        $mode = Printer::CUT_FULL;

        if ($payload->mode == PrintCut::PARTIAL) {
            $mode = Printer::CUT_PARTIAL;
        }

        array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::CUT, $mode));
    }

    public static function handlePrintAlignment($payload, &$job)
    {
        $alignment = PrintJobHelper::parseAlignment($payload->alignment);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::ALIGN, $alignment));
    }

    public static function handlePrintLogo($payload, &$job, $store, $order)
    {
        $pathLogo = public_path() . '/logo_stores/' . $store->id . '.png';

        $baseUrl = 'https://...';
        $url = $baseUrl . '/logo_stores/' . $store->id . '.png';

        if ($payload->from_integration) {
            $integrationName = "";

            $spot = $order->spot->append('name_integration');

            // Si ya tiene el order integration detail creado o la mesa es de integración
            $integrationName = $order->orderIntegrationDetail != null
                ? $order->orderIntegrationDetail->integration_name
                : $spot->name_integration;

            // Remover las integraciones cuando tengamos los logos
            if (
                $integrationName == "" ||
                $integrationName == "local" ||
                $integrationName == "rappi_antojo" ||
                $integrationName == "rappi_pickup" ||
                $integrationName == "didi" ||
                $integrationName == "rappi_pay" ||
                $integrationName == "kiosko" ||
                $integrationName == "meniu" ||
                $integrationName == "delivery" ||
                $integrationName == "groupon" ||
                $integrationName == "lets_eat" ||
                $integrationName == "aloha"
            ) {
                Log::error("No existe detalle de integración para la tienda: " . $store->id);
                return;
            }

            $url = $baseUrl . '/logo_integrations/' . $integrationName . '.png';
            $pathLogo = public_path() . '/logo_integrations/' . $integrationName . '.png';
        }

        $options = [];

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::ALIGN,
            Printer::JUSTIFY_CENTER
        ));

        if (isset($payload->mode)) {
            $options["mode"] = $payload->mode;
        }

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_IMAGE,
            $url,
            $options
        ));
        array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::FEED, 1));
        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::ALIGN,
            Printer::JUSTIFY_LEFT
        ));
    }

    public static function handlePrintImage($payload, &$job, $order)
    {
        if (!isset($payload->url)) {
            Log::error("No existe url definida para la imagen. Provocado por order id: " . $order->id);
            return;
        }

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::ALIGN,
            Printer::JUSTIFY_CENTER
        ));

        $options = [];

        if (isset($payload->mode)) {
            $options["mode"] = $payload->mode;
        }

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_IMAGE,
            $payload->url,
            $options
        ));
        array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::FEED, 1));
        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::ALIGN,
            Printer::JUSTIFY_LEFT
        ));
    }

    public static function handlePrintSalesSummary($payload, &$job, $data, $currencySymbol, $storeMoneyFormat)
    {
        $total_expenses = 0;

        if ($data['expenses']) {
            foreach ($data['expenses'] as $xp) {
                $total_expenses += $xp['value'];
            }

            $total_expenses = $total_expenses / 100;
        }

        $total_cash = $data['value_cash'] + $data['value_open'] - $total_expenses;
        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::ALIGN,
            Printer::JUSTIFY_LEFT
        ));
        $total_sales = PrintJobHelper::formatNumberToMoney($data['value_sales'], $storeMoneyFormat);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Total ventas:                      " . $currencySymbol . $total_sales
        ));
        $total_discount = PrintJobHelper::formatNumberToMoney($data['value_discount'], $storeMoneyFormat);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Total valor en descuentos:         " . $currencySymbol . $total_discount
        ));

        $total_cash = PrintJobHelper::formatNumberToMoney($total_cash, $storeMoneyFormat);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Total cierre de caja:              " . $currencySymbol . $total_cash
        ));
    }

    public static function handlePrintSalesDetails($payload, &$job, $data, $currencySymbol, $storeMoneyFormat)
    {
        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::ALIGN,
            Printer::JUSTIFY_LEFT
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Ventas en Efectivo:                " . $currencySymbol . PrintJobHelper::formatNumberToMoney($data['value_cash'], $storeMoneyFormat)
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Ventas en T/C:                     " . $currencySymbol .  PrintJobHelper::formatNumberToMoney($data['value_card'], $storeMoneyFormat)
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Ventas por transferencia:          " . $currencySymbol . PrintJobHelper::formatNumberToMoney($data['value_transfer'], $storeMoneyFormat)
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Ventas por otros métodos:          " . $currencySymbol . PrintJobHelper::formatNumberToMoney($data['value_others'], $storeMoneyFormat)
        ));

        if ($data['value_rappi_pay'] > 0) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "Ventas por Rappi Pay:          " . $currencySymbol . PrintJobHelper::formatNumberToMoney($data['value_rappi_pay'], $storeMoneyFormat)
            ));
        }

        if ($data['externalValues']) {
            foreach ($data['externalValues'] as $ext) {
                $value = $ext[1] / 100;
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    "Ventas en " . $ext[0] . ":              " . $currencySymbol . PrintJobHelper::formatNumberToMoney($value, $storeMoneyFormat)
                ));
            }
        }

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Total Ventas:                      " . $currencySymbol . PrintJobHelper::formatNumberToMoney($data['value_sales'], $storeMoneyFormat)
        ));
    }

    public static function handlePrintCashierSummary($payload, &$job, $employee, $data, $currencySymbol)
    {
        $store = $employee->store;

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::ALIGN,
            Printer::JUSTIFY_CENTER
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $store->name
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Resumen del día " . TimezoneHelper::localizedNowDateForStore($store)->toDateTimeString()
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::ALIGN,
            Printer::JUSTIFY_LEFT
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Hora de apertura: " . $data['hour_open']
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Hora de cierre: " . $data['hour_close']
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Cierre por empleado: " . $employee->name
        ));
    }

    public static function handlePrintCashierDetails($payload, &$job, $data, $currencySymbol, $storeMoneyFormat)
    {
        $total_expenses = 0;

        if ($data['expenses']) {
            foreach ($data['expenses'] as $xp) {
                $total_expenses += $xp['value'];
            }

            $total_expenses = $total_expenses / 100;
        }

        $total_cash = $data['value_cash'] + $data['value_open'] - $total_expenses;

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::ALIGN,
            Printer::JUSTIFY_LEFT
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Apertura de Caja:                  " . $currencySymbol . PrintJobHelper::formatNumberToMoney($data['value_open'], $storeMoneyFormat)
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Ingresos en Efectivo:              " . $currencySymbol . PrintJobHelper::formatNumberToMoney($data['value_cash'], $storeMoneyFormat)
        ));

        if ($data['expenses']) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "Gastos"
            ));
            foreach ($data['expenses'] as $exp) { //ojo
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    "    " . $exp['name'] . ":                   " . $currencySymbol . PrintJobHelper::formatNumberToMoney($exp['formatValue'], $storeMoneyFormat)
                ));
            }
        }

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Total Cierre de Caja:              " . $currencySymbol .  PrintJobHelper::formatNumberToMoney($total_cash, $storeMoneyFormat)
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Propinas:                          " . $currencySymbol . PrintJobHelper::formatNumberToMoney($data['value_card_tips'], $storeMoneyFormat)
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Cantidad de ordenes anuladas:       " . $data['revoked_orders']
        ));
    }

    public static function handlePrintMode($payload, &$job)
    {
        $mode = PrintJobHelper::parsePrintMode($payload->mode);
        $javaMode = $payload->mode;

        if (isset($payload->fonts)) {
            foreach ($payload->fonts as $font) {
                $mode = $mode | PrintJobHelper::parsePrintMode($font);
            }

            $javaMode = $javaMode . "," . implode(",", $payload->fonts);
        }

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_MODE,
            $mode
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::JAVA_PRINT_MODE,
            $javaMode
        ));
    }

    public static function parsePrintMode($mode)
    {
        $parsedMode = Printer::MODE_FONT_A;
        switch ($mode) {
            case PrintMode::FONT_A:
                $parsedMode = Printer::MODE_FONT_A;
                break;
            case PrintMode::FONT_B:
                $parsedMode = Printer::MODE_FONT_B;
                break;
            case PrintMode::EMPHASIZED:
                $parsedMode = Printer::MODE_EMPHASIZED;
                break;
            case PrintMode::DOUBLE_HEIGHT:
                $parsedMode = Printer::MODE_DOUBLE_HEIGHT;
                break;
            case PrintMode::DOUBLE_WIDTH:
                $parsedMode = Printer::MODE_DOUBLE_WIDTH;
                break;
            case PrintMode::UNDERLINE:
                $parsedMode = Printer::MODE_UNDERLINE;
                break;
        }

        return $parsedMode;
    }

    public static function calculateSpaceAround($noElements, $busySpace, $maxLength)
    {
        if ($noElements > 1) {
            return ($maxLength - $busySpace) / $noElements - 1;
        }
        // Case When is just one element
        else {
            return $maxLength - $busySpace;
        }
    }

    public static function calculateSpaceAmongText($formatType, $noElements, $busySpace, $maxLength)
    {
        switch ($formatType) {
            case FormatType::SPACE_AROUND:
                $spaceAmongText = PrintJobHelper::calculateSpaceAround($noElements, $busySpace, $maxLength);
                break;
        }
        return $spaceAmongText;
    }

    public static function getFormatType($justify)
    {
        $formatType = null;
        switch ($justify) {
            case 'start':
                $formatType = FormatType::START;
                break;
            case 'center':
                $formatType = FormatType::CENTER;
                break;
            case 'left':
                $formatType = FormatType::LEFT;
                break;
            case 'space-around':
                $formatType = FormatType::SPACE_AROUND;
                break;
            case 'space-between':
                $formatType = FormatType::SPACE_BETWEEN;
                break;
        }
        return $formatType;
    }

    public static function handlePrintTextJustify($payload, &$job, $order, $employee, $invoice =  null)
    {
        // In case formatType is null, default value is FormatType::SPACE_AROUND
        $formatType = $payload->justify ?? FormatType::SPACE_AROUND;
        $maxLength = 50; //40 chars on receipt
        $availableSpace = 50; //At the beginning 40 chars are available
        $busySpace = 0; //At the beginning 0 chars are busy
        $processedElements = array();
        $noElements = sizeof($payload->elements);

        $index = 0;

        try {
            foreach ($payload->elements as $element) {
                $processedText = PrintJobHelper::processTokens($element, $order, $employee, $invoice);

                if ($processedText == null) {
                    continue;
                    Log::info('processedText retorna NULL', [$processedText]);
                }

                $processedTextLength = strlen($processedText);

                $busySpace += $processedTextLength;

                if ($index < $noElements) {
                    // Decrement availableSpace n - 1 times
                    $availableSpace -= $processedTextLength;
                }

                $processedElements[] = (object) [
                    'processedText' => $processedText,
                    'processedTextLength' => $processedTextLength
                ];
                $index++;
            }
            // Calculate space among text
            $space_among_text = PrintJobHelper::calculateSpaceAmongText($formatType, $noElements, $busySpace, $maxLength);

            // Line Formatted Space Around
            $lineJustified = '';

            $index = 0;

            // Loop over processedElements
            foreach ($processedElements as $processedElement) {
                if ($index < $noElements - 1) {
                    // Add spacing (=) n - 1 times
                    $lineJustified .= $processedElement->processedText . str_repeat(" ", $space_among_text);
                } else {
                    // Just last Element should not add spacing (=)
                    $lineJustified .= $processedElement->processedText;
                }
                $index++;
            }

            $lineJustified  = str_replace(
                "\\\\n",
                "\n",
                $lineJustified
            );

            $lineJustified  = str_replace(
                "\\n",
                "\n",
                $lineJustified
            );

            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                $lineJustified
            ));
        } catch (Exception $e) {
            Log::info($e);
        }
    }

    public static function handlePrintText($payload, &$job, $order, $employee, $invoice = null)
    {
        $processedText = PrintJobHelper::processTokens($payload, $order, $employee, $invoice);

        if ($processedText == null) {
            return;
        }

        $processedText  = str_replace(
            "\\\\n",
            "\n",
            $processedText
        );

        $processedText  = str_replace(
            "\\n",
            "\n",
            $processedText
        );

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $processedText
        ));
    }

    public static function processTokens($payload, $order, $employee, $invoice = null)
    {
        if (!isset($payload->tokens)) {
            return $payload->text;
        }

        $text = $payload->text;

        if (
            isset($payload->from_integration)
            && $payload->from_integration
        ) {
            if ($order->orderIntegrationDetail == null) {
                return null;
            }
        }

        foreach ($payload->tokens as $token) {
            $needle = "%s";
            $pos = strpos($text, $needle);
            if ($pos === false) {
                continue;
            }

            $replacement = "";

            switch ($token) {
                case "custom_tag":
                    if ($order->custom_identifier != null) {
                        $replacement = $order->custom_identifier;
                    }
                    break;
                case "order_created_at":
                    $replacement = $order->created_at;
                    break;
                case "order_date":
                    $replacement = substr($order->created_at, 0, 11);
                    break;
                case "order_hour":
                    $replacement = substr($order->created_at, 11, 19);
                    break;
                case "order_people":
                    if (
                        $order != null
                        && $order->people != null
                    ) {
                        $replacement = $order->people;
                    }
                    break;
                case "spot_name":
                    if ($order != null && $order->spot_id != null) {
                        $spot = Spot::find($order->spot_id);
                        if ($spot != null && $spot->name != null) {
                            $replacement = $spot->name;
                            if (strpos(strtolower($replacement), 'mesa') === false) {
                                $replacement = "Mesa: " . $replacement;
                            }
                        }
                    }
                    break;
                case "spot_name_clean":
                    if ($order != null && $order->spot_id != null) {
                        $spot = Spot::find($order->spot_id);
                        if ($spot != null && $spot->name != null) {
                            $replacement = $spot->name;
                        }
                    }
                    break;
                case "employee_name":
                    $replacement = $employee->name;
                    break;
                case "customer_name":
                    if (isset($order->orderIntegrationDetail) && $order->orderIntegrationDetail->customer_name !== null) {
                        $replacement = $order->orderIntegrationDetail->customer_name;
                    }
                    break;
                case "delivery_customer_name":
                    if (isset($order->customer) && $order->customer->name !== null) {
                        $replacement = $order->customer->name . " " . $order->customer->last_name;
                    } elseif (isset($order->orderIntegrationDetail) && $order->orderIntegrationDetail->customer_name !== null) {
                        $replacement = $order->orderIntegrationDetail->customer_name;
                    }
                    break;
                case "delivery_customer_address":
                    if (isset($order->address)) {
                        $replacement = $order->address->getFullAddress();
                    }
                    break;
                case "delivery_customer_phone":
                    if (isset($order->customer) && $order->customer->phone !== null) {
                        $replacement = $order->customer->phone;
                    } elseif ($invoice !== null && $invoice->phone != null) {
                        $replacement = $invoice->phone;
                    }
                    break;
                case "integration_name":
                    if (isset($order->orderIntegrationDetail) && $order->orderIntegrationDetail->integration_name !== null) {
                        $replacement = $order->orderIntegrationDetail->integrationNameDescription();
                    }
                    break;
                case "order_number":
                    if (isset($order->orderIntegrationDetail) && $order->orderIntegrationDetail->order_number !== null) {
                        $replacement = $order->orderIntegrationDetail->order_number;
                    }
                    break;
                case "invoice_created_at":
                    $replacement = $invoice->created_at;
                    break;
                case "invoice_document":
                    $replacement = $invoice->document;
                    break;
                case "invoice_name":
                    $replacement = $invoice->name;
                    break;
                case "invoice_address":
                    $replacement = $invoice->address;
                    break;
                case "invoice_phone":
                    $replacement = $invoice->phone;
                    break;
                case "invoice_email":
                    $replacement = $invoice->email;
                    break;
                case "order_employee_name":
                    $replacement = $order->employee->name;
                    break;
                case "billing_code_resolution":
                    $replacement = $employee->store->billing_code_resolution;
                    break;
                case "tax_billing_description":
                    $replacement = $employee->store->company->tax_billing_description;
                    break;
                case "store_name":
                    $replacement = $employee->store->name;
                    break;
                case "store_address":
                    $replacement = $employee->store->address;
                    break;
                case "billing_tradename":
                    // Razón social
                    if (isset($employee->store->company->billingInformation) && $employee->store->company->billingInformation->tradename !== null) {
                        $replacement = $employee->store->company->billingInformation->tradename;
                    }
                    break;
                case "billing_store_code":
                    // Código de la tienda para factura
                    if (isset($employee->store->company->billingInformation) && $employee->store->company->billingInformation->billing_store_code !== null) {
                        $replacement = $employee->store->company->billingInformation->billing_store_code;
                    }
                    break;
                case "company_tin":
                    // NIT de la compañía
                    $replacement = $employee->store->company->TIN;
                    break;
                case "official_invoice_number":
                    $officialInvoiceNumber = $invoice->invoice_number;
                    if (
                        $officialInvoiceNumber != null
                        && $officialInvoiceNumber != ""
                        && $officialInvoiceNumber != "NO_APLICA"
                    ) {
                        $replacement = $officialInvoiceNumber;
                    }

                    break;
                case "document_customer":
                    $documentCustomer = $invoice->document;
                    if ($invoice->name == "CONSUMIDOR FINAL") {
                        $documentCustomer = "0";
                    }

                    $replacement = $documentCustomer;
                    break;
                case "colombia_customer_name":
                    $nameCustomer = $invoice->name;
                    if ($nameCustomer == "CONSUMIDOR FINAL") {
                        $nameCustomer = "General";
                    }

                    $replacement = $nameCustomer;
                    break;
                case "order_instructions":
                    // Intrucciones de la orden de uber eats(no productos sino orden general)
                    if ($order->instructions != null) {
                        $replacement = $order->instructions;
                    }
                    break;
                case "now_timestamp":
                    $replacement = TimezoneHelper::localizedNowDateForStore($employee->store)->toDateTimeString();
                    break;
                case "credit_note_number":
                    if ($order->creditNote != null && $order->creditNote->credit_sequence != null) {
                        $replacement = $order->creditNote->getFormattedNoteNumber();
                    }
                    break;
                case "disposable_items":
                    $disposableItems = "NO";
                    if ($order->disposable_items == 1) {
                        $disposableItems = "SI";
                    }

                    $replacement = $disposableItems;
                    break;
            }

            $text = substr_replace($text, $replacement, $pos, strlen($needle));
        }

        return $text;
    }

    public static function handlePrintProductsHeader($payload, &$job, $identifier, $isReprint = false)
    {
        array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::ALIGN, Printer::JUSTIFY_CENTER));
        if ($payload->show_reprint && $isReprint) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "**** REIMPRESION ****"
            ));
        }

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "-----------------------------------------"
        ));
        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "ORDEN #" . $identifier
        ));
        array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::ALIGN, Printer::JUSTIFY_LEFT));

        if ($payload->show_header_row) {
            $line = "CANT.   PRODUCTO           VALOR";
            array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::PRINT_TEXT, $line));
        }
    }

    public static function handlePrintComandaProducts($printer, $payload, &$job, $groupedDetails, $currencySymbol = "$", $storeMoneyFormat)
    {
        $productsAlignment = PrintJobHelper::parseAlignment($payload->products_alignment);
        $instructionsAlignment = PrintJobHelper::parseAlignment($payload->instructions_alignment);

        foreach ($groupedDetails as $orderDetail) {
            $specFields = $orderDetail['spec_fields'];
            $productName = $specFields['name'];

            array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::ALIGN, $productsAlignment));

            $cost = null;

            if (isset($payload->include_price) && $payload->include_price) {
                $cost = $orderDetail['base_value'];

                if (isset($payload->include_taxes) && $payload->include_taxes) {
                    $cost = $orderDetail['total'];
                }
            }

            PrintJobHelper::printProductLine($printer, $job, $orderDetail['quantity'], $productName, $cost, $currencySymbol, $storeMoneyFormat);

            if (
                isset($payload->lines_before_instructions)
                && $payload->lines_before_instructions > 0
            ) {
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::FEED,
                    $payload->lines_before_instructions
                ));
            }

            if ($payload->show_instructions) {
                $instruction = $specFields['instructions'];
                if ($instruction && $instruction != "") {
                    array_push($job["instructions"], PrintJobHelper::generateInstruction(
                        Command::ALIGN,
                        $instructionsAlignment
                    ));
                    array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::PRINT_TEXT, $instruction));
                }
            }

            if (
                isset($payload->lines_between_products)
                && $payload->lines_between_products > 0
            ) {
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::FEED,
                    $payload->lines_between_products
                ));
            }
        }
    }

    public static function handlePrintPreInvoiceProducts($printer, $payload, &$job, $groupedOrderDetails, $currencySymbol = "$", $storeMoneyFormat)
    {
        foreach ($groupedOrderDetails as $groupedDetail) {
            $orderDetail = new OrderDetail();
            $orderDetail->fill($groupedDetail);

            $cost = $orderDetail->base_value;

            if (isset($payload->include_taxes) && $payload->include_taxes) {
                $cost = $orderDetail->total;
            }

            PrintJobHelper::printProductLine($printer, $job, $orderDetail->quantity, $orderDetail->name_product, $cost, $currencySymbol, $storeMoneyFormat);
            $specFields = $groupedDetail['spec_fields'];

            if (
                isset($payload->show_instructions)
                && $payload->show_instructions
            ) {
                if (
                    isset($payload->lines_before_instructions)
                    && $payload->lines_before_instructions > 0
                ) {
                    array_push($job["instructions"], PrintJobHelper::generateInstruction(
                        Command::FEED,
                        $payload->lines_before_instructions
                    ));
                }

                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    "  " . $specFields['instructions']
                ));
            }

            if (
                isset($payload->lines_between_products)
                && $payload->lines_between_products > 0
            ) {
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::FEED,
                    $payload->lines_between_products
                ));
            }
        }
    }
    public static function handlePrintInvoiceProducts($printer, $payload, &$job, $store, $invoice, $currencySymbol = "$", $storeMoneyFormat)
    {
        if ($invoice->food_service) {
            $subtotalFormatted =  PrintJobHelper::formatNumberToMoney((float)$invoice->subtotal / 100, $storeMoneyFormat);
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "1     SERVICIO DE ALIMENTOS      " . $currencySymbol . $subtotalFormatted
            ));
        } else {
            foreach ($invoice->items as $item) {
                $details = $item->orderDetail;

                $cost = $item->base_value;

                if (isset($payload->include_taxes) && $payload->include_taxes) {
                    $cost = $item->total;
                }

                PrintJobHelper::printProductLine($printer, $job, $item->quantity, $item->product_name, $cost, $currencySymbol, $storeMoneyFormat);
                if ($details != null) {
                    $dataOrderDetail = $details;
                    $dataOrderDetail->append('spec_fields');
                    $instructions = $dataOrderDetail->specFields['instructions'];

                    if (
                        $instructions != ""
                        && isset($payload->show_instructions)
                        && $payload->show_instructions
                    ) {
                        if (
                            isset($payload->lines_before_instructions)
                            && $payload->lines_before_instructions > 0
                        ) {
                            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                                Command::FEED,
                                $payload->lines_before_instructions
                            ));
                        }
                        array_push($job["instructions"], PrintJobHelper::generateInstruction(
                            Command::PRINT_TEXT,
                            "  " . $instructions
                        ));
                    }
                }

                if (
                    isset($payload->lines_between_products)
                    && $payload->lines_between_products > 0
                ) {
                    array_push($job["instructions"], PrintJobHelper::generateInstruction(
                        Command::FEED,
                        $payload->lines_between_products
                    ));
                }
            }
        }
    }

    public static function handlePrintPreInvoicePriceSummary($payload, &$job, $store, $request, $currencySymbol = "$", $storeMoneyFormat)
    {
        $showSubTotal = true;
        $showDiscount = true;
        $showSubTotalDiscounted = true;
        $showTaxes = true;
        $showTotal = true;
        $showTip = true;
        $showTotalAfterTip = true;

        if (isset($payload->show_subtotal)) {
            $showSubTotal = $payload->show_subtotal;
        }
        if (isset($payload->show_discount)) {
            $showDiscount = $payload->show_discount;
        }
        if (isset($payload->show_subtotal_discounted)) {
            $showSubTotalDiscounted = $payload->show_subtotal_discounted;
        }
        if (isset($payload->show_taxes)) {
            $showTaxes = $payload->show_taxes;
        }
        if (isset($payload->show_total)) {
            $showTotal = $payload->show_total;
        }
        if (isset($payload->show_tip)) {
            $showTip = $payload->show_tip;
        }
        if (isset($payload->show_total_after_tip)) {
            $showTotalAfterTip = $payload->show_total_after_tip;
        }

        if (isset($payload->summary_alignment)) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::ALIGN,
                PrintJobHelper::parseAlignment($payload->summary_alignment)
            ));
        }

        $subtotalFormatted = number_format((float) $request->subtotal, 2, '.', '');
        $subtotalFormattedMoney = PrintJobHelper::formatNumberToMoney((float) $request->subtotal, $storeMoneyFormat);

        if ($showSubTotal) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "SUBTOTAL: " . $currencySymbol . $subtotalFormattedMoney . "\n"
            ));
        }

        $discountFormatted = number_format((float) $request->discountValue, 2, '.', '');
        $discountFormattedMoney =   PrintJobHelper::formatNumberToMoney((float) $request->discountValue, $storeMoneyFormat);

        if ($showDiscount) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "DESCUENTO (" . $request->discount_percentage . "%): " . $currencySymbol . $discountFormattedMoney . "\n"
            ));
        }

        $subtotalDiscountedFormatted = $subtotalFormatted - $discountFormatted;
        $subtotalDiscountedFormattedMoney =   PrintJobHelper::formatNumberToMoney((float) $subtotalDiscountedFormatted, $storeMoneyFormat);

        if ($showSubTotalDiscounted) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "SUBTOTAL: " . $currencySymbol . $subtotalDiscountedFormattedMoney . "\n"
            ));
        }

        $total = $subtotalDiscountedFormatted;
        foreach ($request->taxes as $taxDetail) {
            $taxSubtotal = (float)$taxDetail['subtotal'];
            if ($taxSubtotal == 0) {
                continue;
            }

            if ($request->discount_percentage > 0) {
                $taxSubtotal = $taxSubtotal - ($taxSubtotal * $request->discount_percentage / 100);
            }

            $taxFormatted = Helper::bankersRounding($taxSubtotal, 0) / 100;
            $taxFormattedMoney = PrintJobHelper::formatNumberToMoney((float) $taxFormatted, $storeMoneyFormat);
            $total += $taxFormatted;
            if ($showTaxes) {
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    $taxDetail['name'] . ": " . $currencySymbol . $taxFormattedMoney
                ));
            }
        }

        $totalFormatted = number_format((float) $total, 2, '.', '');
        $totalFormattedMoney = PrintJobHelper::formatNumberToMoney((float) $total, $storeMoneyFormat);
        if ($showTotal) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "TOTAL: " . $currencySymbol . $totalFormattedMoney . "\n"
            ));
        }

        if ($request->tip && (float)$request->tip > 0) {
            $tip = (float)$request->tip;
            $tipFormatted = number_format($tip / 100, 2, '.', '');
            $tipFormattedMoney = PrintJobHelper::formatNumberToMoney($tip, $storeMoneyFormat);
            if ($showTip) {
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    "PROPINA: " . $currencySymbol . $tipFormattedMoney . "\n"
                ));
            }

            $totalAterTip = $total + $tipFormatted;
            if ($showTotalAfterTip) {
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    "TOTAL A PAGAR: " . $currencySymbol . PrintJobHelper::formatNumberToMoney($totalAterTip, $storeMoneyFormat) . "\n"
                ));
            }
        }

        if (isset($payload->suggested_tips)) {
            if (isset($payload->suggested_tips_alignment)) {
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::ALIGN,
                    PrintJobHelper::parseAlignment($payload->suggested_tips_alignment)
                ));
            }
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "\nPropina sugerida"
            ));
            foreach ($payload->suggested_tips as $suggestedTip) {
                $calculatedTip = $total * ($suggestedTip / 100);
                $suggestedTipFormatted = number_format($calculatedTip, 2, '.', '');
                $suggestedTipFormattedMoney = PrintJobHelper::formatNumberToMoney($calculatedTip, $storeMoneyFormat);
                $totalAterSuggestedTip = number_format($total + $suggestedTipFormatted, 2, '.', '');
                $totalAterSuggestedTipMoney = PrintJobHelper::formatNumberToMoney($total + $suggestedTipFormatted, $storeMoneyFormat);

                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    $suggestedTip . "%: " . $currencySymbol . $suggestedTipFormattedMoney . " / " . $currencySymbol . $totalAterSuggestedTipMoney
                ));
            }
        }
    }

    public static function handleJsonInstruction($instruction, $printer, &$job, $order, $employee)
    {
        switch ($instruction->cmd) {
            case ProtocolCommand::PRINT_LOGO:
                if (!in_array($printer->name, COMPATIBLE_PRINTERS)) {
                    return;
                }
                PrintJobHelper::handlePrintLogo($instruction->payload, $job, $employee->store, $order);
                break;
            case ProtocolCommand::PRINT_IMAGE:
                if (!in_array($printer->name, COMPATIBLE_PRINTERS)) {
                    return;
                }
                PrintJobHelper::handlePrintImage($instruction->payload, $job, $order);
                break;
            case ProtocolCommand::PRINT_MODE:
                PrintJobHelper::handlePrintMode($instruction->payload, $job);
                break;
            case ProtocolCommand::PRINT_TEXT:
                PrintJobHelper::handlePrintText($instruction->payload, $job, $order, $employee);
                break;
            case ProtocolCommand::PRINT_TEXT_JUSTIFY:
                PrintJobHelper::handlePrintTextJustify($instruction->payload, $job, $order, $employee);
                break;
            case ProtocolCommand::ALIGN:
                PrintJobHelper::handlePrintAlignment($instruction->payload, $job);
                break;
            case ProtocolCommand::CUT:
                PrintJobHelper::handlePrintCut($instruction->payload, $job);
                break;
            case ProtocolCommand::FEED:
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::FEED,
                    $instruction->payload->lines
                ));
                break;
            case ProtocolCommand::PULSE:
                $openOnIntegration = isset($instruction->payload->on_integration)
                    ? $instruction->payload->on_integration
                    : false;

                if ($order->orderIntegrationDetail != null && !$openOnIntegration) {
                    return;
                }

                array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::PULSE, 0));
                break;
        }
    }

    public static function handlePrintInvoicePriceSummary($payload, &$job, $invoice, $currencySymbol = "$", $storeMoneyFormat)
    {
        $subtotalFormatted = PrintJobHelper::formatNumberToMoney((float) $invoice->subtotal / 100, $storeMoneyFormat);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::ALIGN, Printer::JUSTIFY_RIGHT));
        $lengthSubtotal = strlen($currencySymbol . $subtotalFormatted);

        $showSubTotal = true;

        if (isset($payload->show_subtotal)) {
            $showSubTotal = $payload->show_subtotal;
        }

        if ($showSubTotal) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "SUBTOTAL: " . PrintJobHelper::spacesBetween($lengthSubtotal, MAX_LENGTH) . $currencySymbol . $subtotalFormatted
            ));
        }

        $showDiscount = isset($payload->show_discount) ? $payload->show_discount : false;

        if ($showDiscount && (float)$invoice->discount_value > 0) {

            if ($invoice->order->store->company_id == 184) {
                $valueWithDiscount = $invoice->order->order_value - $invoice->total; 
                $discountFormatted = PrintJobHelper::formatNumberToMoney((float) $valueWithDiscount / 100, $storeMoneyFormat);
            } else {
                $discountFormatted = PrintJobHelper::formatNumberToMoney((float) $invoice->discount_value / 100, $storeMoneyFormat);
            }
            $discountPercentage = $invoice->discount_percentage;

            if (floor($invoice->discount_percentage) != $invoice->discount_percentage) {
                $discountPercentage = number_format((float)$invoice->discount_percentage, 2, '.', '');
            }

            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "DESCUENTO: (" . $discountPercentage . "%):"
                    . PrintJobHelper::spacesBetween($lengthSubtotal, MAX_LENGTH) . $currencySymbol . $discountFormatted
            ));
        }

        if (isset($invoice->noTaxSubtotal)) {
            $noTaxSubtotalFormatted =   PrintJobHelper::formatNumberToMoney((float) $invoice->noTaxSubtotal / 100, $storeMoneyFormat);

            $showNoTaxSubTotal = true;

            if (isset($payload->show_notax_subTotal)) {
                $showNoTaxSubTotal = $payload->show_notax_subTotal;
            }

            if ($showNoTaxSubTotal) {
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    "SUBTOTAL (0%): " . PrintJobHelper::spacesBetween($lengthSubtotal, MAX_LENGTH) . $currencySymbol . $noTaxSubtotalFormatted
                ));
            }
        }

        if (isset($invoice->productTaxes)) {
            $showProductTaxes = true;

            if (isset($payload->show_product_taxes)) {
                $showProductTaxes = $payload->show_product_taxes;
            }

            foreach ($invoice->productTaxes as $taxDetail) {
                $taxSubtotal = (float)$taxDetail['tax_subtotal'];
                if ($taxSubtotal == 0) {
                    continue;
                }

                $taxFormatted =   PrintJobHelper::formatNumberToMoney($taxSubtotal / 100, $storeMoneyFormat);
                $taxPercentage = $taxDetail['percentage'];

                if ($taxPercentage == 0) {
                    continue;
                }

                if ($showProductTaxes) {
                    $length = strlen($currencySymbol . $taxFormatted);
                    array_push(
                        $job["instructions"],
                        PrintJobHelper::generateInstruction(
                            Command::PRINT_TEXT,
                            "SUBTOTAL (" . $taxPercentage . "%): " .
                                PrintJobHelper::spacesBetween($length, MAX_LENGTH) . $currencySymbol . $taxFormatted
                        )
                    );
                }
            }
        }

        $showInvoiceTaxes = true;

        if (isset($payload->show_invoice_taxes)) {
            $showInvoiceTaxes = $payload->show_invoice_taxes;
        }

        foreach ($invoice->taxDetails as $taxDetail) {
            $taxFormatted = PrintJobHelper::formatNumberToMoney((float) $taxDetail->subtotal / 100, $storeMoneyFormat);
            $taxName = $taxDetail->tax_name;

            if ($showInvoiceTaxes) {
                $length = strlen($currencySymbol . $taxFormatted);
                array_push(
                    $job["instructions"],
                    PrintJobHelper::generateInstruction(
                        Command::PRINT_TEXT,
                        $taxName . ": " . PrintJobHelper::spacesBetween($length, MAX_LENGTH) . $currencySymbol . $taxFormatted
                    )
                );
            }
        }

        $showTotal = true;

        if (isset($payload->show_total)) {
            $showTotal = $payload->show_total;
        }

        $numeroTotal = (float) $invoice->total / 100;
        $totalFormatted = PrintJobHelper::formatNumberToMoney($numeroTotal, $storeMoneyFormat);
        $lengthTotal = strlen($currencySymbol . $totalFormatted);

        if ($showTotal) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "TOTAL: " . PrintJobHelper::spacesBetween($lengthTotal, MAX_LENGTH) . $currencySymbol . $totalFormatted
            ));
        }

        if ($invoice->tip > 0) {
            $showTip = true;

            if (isset($payload->show_tip)) {
                $showTip = $payload->show_tip;
            }

            $tipFormatted =  PrintJobHelper::formatNumberToMoney((float) $invoice->tip / 100, $storeMoneyFormat);
            $lengthTip = strlen($currencySymbol . $tipFormatted);

            if ($showTip) {
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    "PROPINA: " . PrintJobHelper::spacesBetween($lengthTotal, MAX_LENGTH) . $currencySymbol . $tipFormatted
                ));
            }

            $totalAterTip = $invoice->total + $invoice->tip;
            $totalAfterTipFormatted = PrintJobHelper::formatNumberToMoney((float) $totalAterTip / 100, $storeMoneyFormat);
            $length = strlen($currencySymbol . $totalAfterTipFormatted);

            $showTotalAfterTip = true;

            if (isset($payload->show_total_after_tip)) {
                $showTotalAfterTip = $payload->show_total_after_tip;
            }

            if ($showTotalAfterTip) {
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    "TOTAL A PAGAR: " . PrintJobHelper::spacesBetween($length, MAX_LENGTH) . $currencySymbol . $totalAfterTipFormatted
                ));
            }
        }
    }

    public static function handlePrintPaymentMethod($payload, &$job, $invoice, $currencySymbol = "$", $storeMoneyFormat)
    {
        // Mostrar método de pago
        $order = $invoice->order;
        $paymentMethod = "";
        $receivedValue = 0;
        if ($order->payments !== null) {
            foreach ($order->payments as $payment) {
                $receivedValue += $payment->total;
                switch ($payment->type) {
                    case PaymentType::CASH:
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Efectivo');
                        break;
                    case PaymentType::DEBIT:
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'T. Débito');
                        break;
                    case PaymentType::CREDIT:
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'T. Crédito');
                        break;
                    case PaymentType::TRANSFER:
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Transferencia');
                        break;
                    case PaymentType::OTHER:
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Otros');
                        break;
                    case PaymentType::RAPPI_PAY:
                        $paymentMethod = ReportHelperTrait::appendMethod($paymentMethod, 'Rappi Pay');
                        break;
                }
            }

            // Handle integration spots
            if ($order->orderIntegrationDetail != null) {
                $integrationName = $order->orderIntegrationDetail->integrationNameDescription();
                $paymentMethod = $integrationName == "" ? "T. Crédito" : $integrationName;
            }
        } else {
            $integrationName = $order->orderIntegrationDetail != null
                ? $order->orderIntegrationDetail->integrationNameDescription()
                : "";
            $paymentMethod = $integrationName;
            if ($integrationName == "") {
                $paymentMethod = "Efectivo";
            }
            $receivedValue += $invoice->total;
        }
        array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::FEED, 1));
        $lengthPaymentMethod = strlen($paymentMethod);
        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "MÉTODO DE PAGO: " . PrintJobHelper::spacesBetween($lengthPaymentMethod, MAX_LENGTH) . $paymentMethod
        ));
        $formatReceivedValue = PrintJobHelper::formatNumberToMoney((float)$receivedValue / 100, $storeMoneyFormat);

        $lengthReceive = strlen($currencySymbol . $formatReceivedValue);
        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "VALOR RECIBIDO: " . PrintJobHelper::spacesBetween($lengthReceive, MAX_LENGTH) . $currencySymbol . $formatReceivedValue
        ));
        $formatChangeValue = PrintJobHelper::formatNumberToMoney((float)$order->change_value / 100, $storeMoneyFormat);
        $lengthChangeValue = strlen($currencySymbol . $formatChangeValue);
        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "CAMBIO: " . PrintJobHelper::spacesBetween($lengthChangeValue, MAX_LENGTH) . $currencySymbol . $formatChangeValue
        ));
    }

    public static function generateInstruction($command, $text, $options = null)
    {
        $instruction = [
            "cmd" => $command
        ];

        if (isset($options) && $options != null) {
            $instruction = array_merge($instruction, $options);
        }

        if (isset($text)) {
            $instruction["text"] = $text;
        }
        return $instruction;
    }

    public static function isPrinted($orderDetail)
    {
        // Separo todos los estados que tenga ese orderDetail
        $statuses = $orderDetail->processStatus->pluck("process_status")->toArray();

        return in_array(2, $statuses);
    }

    public static function isReprint($orderDetails)
    {
        $count = 0;

        foreach ($orderDetails as $orderDetail) {
            if (PrintJobHelper::isPrinted($orderDetail)) {
                $count++;
            }
        }

        return $count == count($orderDetails);
    }

    public static function markAsPrinted($orderDetail)
    {
        if (PrintJobHelper::isPrinted($orderDetail)) {
            return;
        }

        $processStatus = new OrderDetailProcessStatus();
        $processStatus->process_status = 2;
        $processStatus->order_detail_id = $orderDetail->id;
        $processStatus->save();
        // Solo le agrego el * al compound_key si aun no lo tiene (se requiere por el momento para las agrupaciones).
        // Si puede darse el caso que tenga el * y aun no este impreso (cuando se incrementa la cantidad de un producto
        // impreso), por eso es necesaria esta validacion.
        $newCompoundKey = (strpos($orderDetail->compound_key, '*') !== false)
            ? $orderDetail->compound_key
            : '*' . $orderDetail->compound_key;
        $orderDetail->compound_key = $newCompoundKey;
        $orderDetail->save();
    }

    public static function printProductLine($printer, &$job, $quantity, $productName, $cost = null, $currencySymbol = "$", $storeMoneyFormat)
    {
        $maxLength = $printer->paper_size; //40 chars on receipt

        $quantityString = $quantity < 10
            ? $quantity . "     "
            : $quantity . "    ";

        $itemLine = $quantityString . $productName;

        $costFormatted = "";

        if (isset($cost) && $cost != null) {
            $costFormatted = "  " . $currencySymbol . PrintJobHelper::formatNumberToMoney($cost / 100, $storeMoneyFormat);
        }

        if (strlen($itemLine) + strlen($costFormatted) > $maxLength) {
            $itemLine = $itemLine . "\n" . str_repeat(" ", $maxLength - strlen($costFormatted));
        } else {
            //fill empty space
            $itemLine = $itemLine . str_repeat(" ", $maxLength - strlen($costFormatted) - strlen($itemLine));
        }

        $itemLine = $itemLine . $costFormatted;
        array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::PRINT_TEXT, $itemLine));
    }

    public static function groupDetailsByPrinter($orderDetails, $printers, $isReprint, $action = PrinterAction::COMANDA)
    {
        $groupedDetails = array();

        foreach ($orderDetails as $orderDetail) {
            if (PrintJobHelper::isPrinted($orderDetail) && !$isReprint) {
                continue;
            }

            $productDetail = $orderDetail->productDetail;

            $rawPrinters = $printers;

            // Se tienen varias localidades y varias impresoras
            $locations = $productDetail->locations;
            if (sizeof($locations) > 0) {
                $rawPrinters = array();
                foreach ($locations as $location) {
                    if (!is_null($location->printers)) {
                        foreach ($location->printers as $locationPrinter) {
                            array_push($rawPrinters, $locationPrinter);
                        }
                    }
                }
            }

            foreach ($rawPrinters as $rawPrinter) {
                if ($rawPrinter->actions != $action) {
                    continue;
                }

                if (!array_key_exists($rawPrinter->id, $groupedDetails)) {
                    $groupedDetails[$rawPrinter->id] = array();
                }

                array_push($groupedDetails[$rawPrinter->id], $orderDetail);
            }
        }

        return $groupedDetails;
    }

    public static function spacesBetween($stringLength, $maxLengthLine)
    {
        $excessLength = $maxLengthLine - $stringLength;
        $spaces = "";
        if ($excessLength > 0) {
            for ($i = 0; $i < $excessLength; $i++) {
                $spaces = $spaces . " ";
            }
        }
        return $spaces;
    }

    public static function handlePrintXZReportHeader($payload, &$job, $employee, $data, $type, $extraData, $conversion)
    {
        $store = $employee->store;

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::ALIGN,
            Printer::JUSTIFY_CENTER
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $store->name
        ));

        if (!is_null($store->address)) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                $store->address
            ));
        }

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::FEED,
            2
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::ALIGN,
            Printer::JUSTIFY_LEFT
        ));

        if ($type === "X") {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "Corte X de la caja: " . $data["cashier_number"]
            ));
            $printText = "";
            if ($data["date_open"] != $data["date_close"]) {
                $printText = "De " . $data["date_open"] . " " . $data["hour_open"]
                    . " a " . $data["date_close"] . " " . $data["hour_close"];
            } else {
                $printText = "Del " . $data["date_open"] . " (" . $data["hour_open"] . " a " . $data["hour_close"] . ")";
            }
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                $printText
            ));
        } else {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "Corte Z: " . $data["cashier_number"]
            ));
            $printText = "";
            if ($data["date_open"] != $data["date_close"]) {
                $printText = "De " . $data["date_open"] . " " . $data["hour_open"]
                    . " a " . $data["date_close"] . " " . $data["hour_close"];
            } else {
                $printText = "Del " . $data["date_open"] . " (" . $data["hour_open"] . " a " . $data["hour_close"] . ")";
            }
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                $printText
            ));
        }

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Rango de tickets: " . $extraData["first_invoice_number"] . " a " . $extraData["last_invoice_number"]
        ));
        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Número de transacciones: " . $extraData["count_orders"]
        ));
        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Tipo Cambio Dólares: " . number_format($conversion / 100, 2, '.', ',')
        ));
    }

    public static function handlePrintXZReportSummary($payload, &$job, $employee, $data, $currencySymbol, $type, $storeMoneyFormat)
    {
        $store = $employee->store;
        $charsPerLine = CHARS_PER_LINE;

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "Cajero: " . $employee->name
        ));

        $textLine = "Saldo de apertura: ";
        $valueOpen = PrintJobHelper::formatNumberToMoney($data['value_open'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $valueOpen);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $valueOpen
        ));

        $textLine = "Total de ventas: ";
        $totalSales =  PrintJobHelper::formatNumberToMoney($data['value_sales'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $totalSales);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $totalSales
        ));

        $textLine = "Comandas por facturar: ";
        $pendingSales = PrintJobHelper::formatNumberToMoney($data['value_pending_orders'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $pendingSales);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $pendingSales
        ));

        $textLine = "Ventas generales: ";
        $allSales = PrintJobHelper::formatNumberToMoney($data['value_sales'] + $data['value_pending_orders'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $allSales);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $allSales
        ));

        $textLine = "Ordenes canceladas: ";
        $revokeSales =  PrintJobHelper::formatNumberToMoney($data['value_revoked_orders'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $revokeSales);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $revokeSales
        ));
    }

    public static function handlePrintXZReportPaymentValues($payload, &$job, $data, $currencySymbol, $storeMoneyFormat)
    {
        $charsPerLine = CHARS_PER_LINE;

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "TOTAL DE PAGOS RECIBIDOS"
        ));

        $textLine = "Efectivo (" . $data['count_orders_cash'] . "): ";
        $valueCash = PrintJobHelper::formatNumberToMoney($data['value_cash'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $valueCash);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $valueCash
        ));

        $textLine = "Tarjetas (" . $data['count_orders_card'] . "): ";
        $valueCard = PrintJobHelper::formatNumberToMoney($data['value_card'] + $data['value_tip_card'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $valueCard);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $valueCard
        ));

        $textLine = "RappiPay (" . $data['count_orders_rappi_pay'] . "): ";
        $valueRappiPay =  PrintJobHelper::formatNumberToMoney($data['value_rappi_pay'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $valueRappiPay);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $valueRappiPay
        ));

        if ($data['externalValues']) {
            $countExternals = $data["count_orders_external"];
            foreach ($data['externalValues'] as $value) {
                $countExternal = $countExternals[$value[0]];
                $textLine = $value[0] . " (" . $countExternal . "): ";
                $valueExternal =   PrintJobHelper::formatNumberToMoney($value[1] / 100, $storeMoneyFormat);
                $chars = strlen($textLine) + strlen($currencySymbol . $valueExternal);
                $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    $textLine . $separators . $currencySymbol . $valueExternal
                ));
            }
        }

        // No mostrar valor de cambio por el momento
        // $textLine = "Cambios: ";
        // $changeValue = number_format($data['value_change'], 2, '.', ',');
        // $chars = strlen($textLine) + strlen($currencySymbol . $changeValue);
        // $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        // array_push($job["instructions"], PrintJobHelper::generateInstruction(
        //     Command::PRINT_TEXT,
        //     $textLine . $separators . $currencySymbol . $changeValue
        // ));

        $textLine = "TOTAL RECIBIDO: ";
        $totalValue =  PrintJobHelper::formatNumberToMoney($data['value_sales'] + $data['value_tip_card'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $totalValue);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $totalValue
        ));

        $textLine = "TOT. EFECTIVO Y TARJ.: ";
        $totalCashCardValue = PrintJobHelper::formatNumberToMoney($data['value_cash'] + $data['value_card'] + $data['value_tip_card'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $totalCashCardValue);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $totalCashCardValue
        ));

        $textLine = "TOTAL EFECTIVO: ";
        $totalCash = PrintJobHelper::formatNumberToMoney($data['value_cash'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $totalCash);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $totalCash
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::FEED,
            1
        ));

        $textLine = "Saldo de apertura: ";
        $valueOpen =  PrintJobHelper::formatNumberToMoney($data['value_open'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $valueOpen);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $valueOpen
        ));

        $textLine = "EFECTIVO EN CAJA: ";
        $totalCash = PrintJobHelper::formatNumberToMoney($data['value_open'] + $data['value_cash'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $totalCash);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $totalCash
        ));

        $totalExpenses = 0;
        foreach ($data['expenses'] as $expense) {
            $textLine = $expense['name'];
            $totalExpenses += $expense['value'];
        }

        $textLine = "TOTAL EGRESOS: ";
        $totalExpensesStr = PrintJobHelper::formatNumberToMoney($totalExpenses / 100, $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $totalExpensesStr);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $totalExpensesStr
        ));

        $textLine = "Propinas a tarjetas: ";
        $tipsCard = PrintJobHelper::formatNumberToMoney($data['value_tip_card'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $tipsCard);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $tipsCard
        ));

        $textLine = "Propinas a efectivo: ";
        $tipsCash = PrintJobHelper::formatNumberToMoney($data['value_tip_cash'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $tipsCash);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $tipsCash
        ));

        $textLine = "TOTAL A ENTREGAR A EFECTIVO: ";
        $finalCash = PrintJobHelper::formatNumberToMoney($data['value_open'] + $data['value_cash'] - $data['value_tip_card'] - ($totalExpenses / 100), $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $finalCash);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $finalCash
        ));
    }

    public static function handlePrintExpensesValues($payload, &$job, $data, $currencySymbol, $storeMoneyFormat)
    {
        $charsPerLine = CHARS_PER_LINE;

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "EGRESOS"
        ));

        $total = 0;
        foreach ($data['expenses'] as $expense) {
            $textLine = $expense['name'];
            $total += $expense['value'];
            $valueExpense = PrintJobHelper::formatNumberToMoney($expense['formatValue'], $storeMoneyFormat);
            $chars = strlen($textLine) + strlen($currencySymbol . $valueExpense);
            $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                $textLine . $separators . $currencySymbol . $valueExpense
            ));
        }

        $textLine = "TOTALES: ";
        $totalValue = PrintJobHelper::formatNumberToMoney($total / 100, $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $totalValue);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $totalValue
        ));
    }

    public static function handlePrintXZReportStats($payload, &$job, $data, $currencySymbol, $externalData, $storeMoneyFormat)
    {
        $charsPerLine = CHARS_PER_LINE;

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "DATOS ESTADISTICOS"
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "VENTAS SERVICIO EN MESAS"
        ));

        $textLine = "Numero de clientes: ";
        $countCustomers = number_format($externalData['customer_local'], 0, '.', ',');
        $chars = strlen($textLine) + strlen($countCustomers);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $countCustomers
        ));

        $textLine = "Mesas atendidas: ";
        $countLocalSpots = number_format($externalData['count_local_orders'], 0, '.', ',');
        $chars = strlen($textLine) + strlen($countLocalSpots);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $countLocalSpots
        ));

        $localValue = $data['value_sales'] - $data['value_deliveries'];

        $textLine = "Promedio por mesa: ";
        $value = 0;
        if ($externalData['count_local_orders'] != 0) {
            $value = $localValue / $externalData['count_local_orders'];
        }
        $countLocalSpots = PrintJobHelper::formatNumberToMoney($value, $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $countLocalSpots);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $countLocalSpots
        ));

        $textLine = "Monto venta total: ";
        $totalValueLocal = PrintJobHelper::formatNumberToMoney($localValue, $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $totalValueLocal);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $totalValueLocal
        ));

        $textLine = "Promedio por cliente: ";
        $value = 0;
        if ($externalData['customer_local'] != 0) {
            $value = $localValue / $externalData['customer_local'];
        }
        $averageLocal = PrintJobHelper::formatNumberToMoney($value, $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $averageLocal);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $averageLocal
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::FEED,
            1
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "VENTAS SERVICIO DELIVERY"
        ));

        $textLine = "Numero de clientes: ";
        $countCustomersDelivery = number_format($externalData['customer_delivery'], 0, '.', ',');
        $chars = strlen($textLine) + strlen($countCustomersDelivery);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $countCustomersDelivery
        ));

        $textLine = "Monto venta total: ";
        $totalValueDelivery = PrintJobHelper::formatNumberToMoney($data['value_deliveries'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $totalValueDelivery);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $totalValueDelivery
        ));

        $textLine = "Promedio por cliente: ";
        $value = 0;
        if ($externalData['customer_delivery'] != 0) {
            $value = $data['value_deliveries'] / $externalData['customer_delivery'];
        }
        $averageDelivery = PrintJobHelper::formatNumberToMoney($value, $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $averageDelivery);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $averageDelivery
        ));
    }

    public static function handlePrintXZReportTaxesAndTypes($payload, &$job, $data, $currencySymbol, $externalData, $storeMoneyFormat)
    { //OJO
        $charsPerLine = CHARS_PER_LINE;

        if (count($externalData['tax_values_details']) > 0) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "TOTALES TASAS (SIN VALOR DE LA TASA)"
            ));

            foreach ($externalData['tax_values_details'] as $detail) {
                $textLine = "TASA " . $detail["percentage"] . "%";
                $drinkSubtotal = number_format(Helper::bankersRounding($detail['total'] / 100, 2), 2, '.', ',');
                $drinkSubtotalFormatted = PrintJobHelper::formatNumberToMoney(Helper::bankersRounding($detail['total'] / 100, 2), $storeMoneyFormat);
                $chars = strlen($textLine) + strlen($currencySymbol . $drinkSubtotalFormatted);
                $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    $textLine . $separators . $currencySymbol . $drinkSubtotalFormatted
                ));
            }

            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::FEED,
                1
            ));
        }

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "IMPUESTOS Y TOTALES"
        ));

        $textLine = "Bebidas: ";
        $drinkSubtotal = number_format($externalData['drink_sutotal'], 2, '.', ',');
        $drinkSubtotalFormatted = PrintJobHelper::formatNumberToMoney($externalData['drink_sutotal'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $drinkSubtotalFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $drinkSubtotalFormatted
        ));

        $textLine = "Alimentos: ";
        $foodSubtotal = number_format($externalData['food_sutotal'], 2, '.', ',');
        $foodSubtotalFormatted = PrintJobHelper::formatNumberToMoney($externalData['food_sutotal'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $foodSubtotalFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $foodSubtotalFormatted
        ));

        $textLine = "Otros articulos: ";
        $otherSubtotal = number_format($externalData['other_sutotal'], 2, '.', ',');
        $otherSubtotalFormatted = PrintJobHelper::formatNumberToMoney($externalData['other_sutotal'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $otherSubtotalFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $otherSubtotalFormatted
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "                          ------------------"
        ));

        $subtotal = $externalData['drink_sutotal'] + $externalData['food_sutotal'] + $externalData['other_sutotal'];
        $textLine = "Subtotal: ";
        $subtotalAll = number_format($subtotal, 2, '.', ',');
        $subtotalAllFormatted = PrintJobHelper::formatNumberToMoney($subtotal, $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $subtotalAllFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $subtotalAllFormatted
        ));

        $tax = $externalData['food_tax'] + $externalData['drink_tax'] + $externalData['other_tax'];
        $textLine = "IVA: ";
        $taxAll = number_format($tax, 2, '.', ',');
        $taxAllFormatted = PrintJobHelper::formatNumberToMoney($taxAll, $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $taxAllFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $taxAllFormatted
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "                          ------------------"
        ));

        $total = $externalData['food_total'] + $externalData['drink_total'] + $externalData['other_total'];
        $textLine = "Totales: ";
        $totalAll = number_format($total, 2, '.', ',');
        $totalAllFormatted = PrintJobHelper::formatNumberToMoney($totalAll, $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $totalAllFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $totalAllFormatted
        ));

        $textLine = "Descuentos: ";
        $discountAll = number_format($externalData['total_discount'], 2, '.', ',');
        $discountAllFormatted = PrintJobHelper::formatNumberToMoney($externalData['total_discount'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $discountAllFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $discountAllFormatted
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "                          ------------------"
        ));

        $finalTotalData = $total - $externalData['total_discount'];
        $textLine = "TOTALES: ";
        $finalTotal = number_format($finalTotalData, 2, '.', ',');
        $finalTotalFormatted = PrintJobHelper::formatNumberToMoney($finalTotalData, $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $finalTotalFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $finalTotalFormatted
        ));

        $textLine = "Propinas a tarjetas: ";
        $tipsCard = number_format($data['value_tip_card'], 2, '.', ',');
        $tipsCardFormatted = PrintJobHelper::formatNumberToMoney($data['value_tip_card'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $tipsCardFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $tipsCardFormatted
        ));

        $textLine = "Propinas a efectivo: ";
        $tipsCash = number_format($data['value_tip_cash'], 2, '.', ',');
        $tipsCashFormatted = PrintJobHelper::formatNumberToMoney($data['value_tip_cash'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $tipsCashFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $tipsCashFormatted
        ));

        $textLine = "TOTAL GENERAL: ";
        $generalTotal = number_format($finalTotalData + $data['value_tip_card'], 2, '.', ',');
        $generalTotalFormatted = PrintJobHelper::formatNumberToMoney($finalTotalData + $data['value_tip_card'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $generalTotalFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $generalTotalFormatted
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::FEED,
            1
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "BEBIDAS"
        ));

        $textLine = "Neto: ";
        $drinkSubtotal = number_format($externalData['drink_sutotal'], 2, '.', ',');
        $drinkSubtotalFormatted = PrintJobHelper::formatNumberToMoney($externalData['drink_sutotal'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $drinkSubtotalFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $drinkSubtotalFormatted
        ));

        $textLine = "IVA: ";
        $drinkTax = number_format($externalData['drink_tax'], 2, '.', ',');
        $drinkTaxFormatted = PrintJobHelper::formatNumberToMoney($externalData['drink_tax'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $drinkTaxFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $drinkTaxFormatted
        ));

        $textLine = "Descuento: ";
        $drinkDiscount = number_format($externalData['total_discount'], 2, '.', ',');
        $drinkDiscountFormatted = PrintJobHelper::formatNumberToMoney($externalData['total_discount'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $drinkDiscountFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $drinkDiscountFormatted
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "ALIMENTOS"
        ));

        $textLine = "Neto: ";
        $foodSubtotal = number_format($externalData['food_sutotal'], 2, '.', ',');
        $foodSubtotalFormatted = PrintJobHelper::formatNumberToMoney($externalData['food_sutotal'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $foodSubtotalFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $foodSubtotalFormatted
        ));

        $textLine = "IVA: ";
        $foodTax = number_format($externalData['food_tax'], 2, '.', ',');
        $foodTaxFormatted = PrintJobHelper::formatNumberToMoney($externalData['food_tax'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $foodTaxFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $foodTaxFormatted
        ));

        $textLine = "Descuento: ";
        $drinkDiscount = number_format($externalData['total_discount'], 2, '.', ',');
        $drinkDiscountFormatted = PrintJobHelper::formatNumberToMoney($externalData['total_discount'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $drinkDiscountFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $drinkDiscountFormatted
        ));

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "OTROS ARTICULOS"
        ));

        $textLine = "Neto: ";
        $otherSubtotal = number_format($externalData['other_sutotal'], 2, '.', ',');
        $otherSubtotalFormatted = PrintJobHelper::formatNumberToMoney($externalData['other_sutotal'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $otherSubtotalFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $otherSubtotalFormatted
        ));

        $textLine = "IVA: ";
        $otherTax = number_format($externalData['other_tax'], 2, '.', ',');
        $otherTaxFormatted = PrintJobHelper::formatNumberToMoney($externalData['other_tax'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $otherTaxFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $otherTaxFormatted
        ));

        $textLine = "Descuento: ";
        $drinkDiscount = number_format($externalData['total_discount'], 2, '.', ',');
        $drinkDiscountFormatted = PrintJobHelper::formatNumberToMoney($externalData['total_discount'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $drinkDiscountFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $drinkDiscountFormatted
        ));
    }

    public static function handlePrintXZReportCardsDetails($payload, &$job, $data, $currencySymbol, $externalData, $storeMoneyFormat)
    {
        $charsPerLine = CHARS_PER_LINE;

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            "DESGLOSE DE TARJETAS"
        ));

        $cardDetails = $externalData["card_details_payments"];
        foreach ($cardDetails as $cardDetail) {
            if (count($cardDetail["transactions"]) > 0) {
                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::FEED,
                    1
                ));

                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    $cardDetail["name"]
                ));

                foreach ($cardDetail["transactions"] as $transaction) {
                    $lastDigits = is_null($transaction["last_digits"]) ? "N/A" : $transaction["last_digits"];
                    $chars1 = strlen($lastDigits) + strlen("F:" . $transaction["invoice_number"]);
                    $separators1 = PrintJobHelper::spacesBetween($chars1, $charsPerLine);
                    array_push($job["instructions"], PrintJobHelper::generateInstruction(
                        Command::PRINT_TEXT,
                        $lastDigits . $separators1 . "F:" . $transaction["invoice_number"]
                    ));

                    $value = number_format($transaction["value"], 2, '.', ',');
                    $tip = number_format($transaction["tip"], 2, '.', ',');
                    $valueFormatted = PrintJobHelper::formatNumberToMoney($transaction['value'], $storeMoneyFormat);
                    $tipFormatted = PrintJobHelper::formatNumberToMoney($transaction['tip'], $storeMoneyFormat);
                    $chars2 = strlen("Valor:" . $valueFormatted) + strlen("Propina:" . $tipFormatted);
                    $separators2 = PrintJobHelper::spacesBetween($chars2, $charsPerLine);
                    array_push($job["instructions"], PrintJobHelper::generateInstruction(
                        Command::PRINT_TEXT,
                        "Valor:" . $valueFormatted . $separators2 . "Propina:" . $tipFormatted
                    ));
                    array_push($job["instructions"], PrintJobHelper::generateInstruction(
                        Command::PRINT_TEXT,
                        "   "
                    ));
                }
            }
        }
        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::FEED,
            1
        ));
        $textLine = "Total tarjetas: ";
        $totalValueCard = number_format($data['value_card'], 2, '.', ',');
        $totalValueCardFormatted = PrintJobHelper::formatNumberToMoney($data['value_card'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $totalValueCardFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $totalValueCardFormatted
        ));

        $textLine = "Total propinas: ";
        $totalTipCard = number_format($data['value_tip_card'], 2, '.', ',');
        $totalTipCardFormatted = PrintJobHelper::formatNumberToMoney($data['value_tip_card'], $storeMoneyFormat);
        $chars = strlen($textLine) + strlen($currencySymbol . $totalTipCardFormatted);
        $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

        array_push($job["instructions"], PrintJobHelper::generateInstruction(
            Command::PRINT_TEXT,
            $textLine . $separators . $currencySymbol . $totalTipCardFormatted
        ));
    }

    public static function handlePrintXZReportEmployeeDetails($payload, &$job, $data, $currencySymbol, $externalData, $storeMoneyFormat)
    {
        $charsPerLine = CHARS_PER_LINE;
        $employeeTransactionsDetails = $externalData["employee_details_transactions"];

        if (count($employeeTransactionsDetails) > 0) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "DETALLE DE VENTAS Y PROPINAS"
            ));

            foreach ($employeeTransactionsDetails as $transactionsDetail) {
                if ($transactionsDetail["value"] > 0 && $transactionsDetail["name"] != "Integración") {
                    array_push($job["instructions"], PrintJobHelper::generateInstruction(
                        Command::FEED,
                        1
                    ));

                    array_push($job["instructions"], PrintJobHelper::generateInstruction(
                        Command::PRINT_TEXT,
                        $transactionsDetail["name"]
                    ));

                    $totalValue = number_format(round($transactionsDetail["value"] / 100, 2), 2, '.', ',');
                    $totalValueFormatted = PrintJobHelper::formatNumberToMoney(round($transactionsDetail["value"] / 100, 2), $storeMoneyFormat);
                    array_push($job["instructions"], PrintJobHelper::generateInstruction(
                        Command::PRINT_TEXT,
                        "Total de ventas: " . $currencySymbol . $totalValueFormatted
                    ));

                    $totalTips = number_format(round($transactionsDetail["tips"] / 100, 2), 2, '.', ',');
                    $totalTipsFormatted = PrintJobHelper::formatNumberToMoney(round($transactionsDetail["tips"] / 100, 2), $storeMoneyFormat);
                    array_push($job["instructions"], PrintJobHelper::generateInstruction(
                        Command::PRINT_TEXT,
                        "Propinas: " . $currencySymbol . $totalTipsFormatted
                    ));
                }
            }
        }
    }

    public static function handlePrintXZReportRappiDetails($payload, &$job, $data, $currencySymbol, $externalData, $storeMoneyFormat)
    {
        $charsPerLine = CHARS_PER_LINE;
        $rappiTransactionsDetails = $externalData["rappi_values_details"];

        if (count($rappiTransactionsDetails) > 0) {
            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                "DETALLE DE CREDITOS A CLIENTES"
            ));

            $total = 0;
            foreach ($rappiTransactionsDetails as $transactionsDetail) {
                $textLine = $transactionsDetail["invoice_number"] . " " . $transactionsDetail["name"];
                $totalRappiOrder = number_format($transactionsDetail['value'], 2, '.', ',');
                $totalRappiOrderFormatted = PrintJobHelper::formatNumberToMoney($transactionsDetail['value'], $storeMoneyFormat);
                $total += $transactionsDetail['value'];
                $chars = strlen($textLine) + strlen($currencySymbol . $totalRappiOrderFormatted);
                $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    $textLine . $separators . $currencySymbol . $totalRappiOrderFormatted
                ));
            }

            $textLine = "TOTALES (" . count($rappiTransactionsDetails) . ")";
            $finalTotal = number_format($total, 2, '.', ',');
            $finalTotalFormatted = PrintJobHelper::formatNumberToMoney($total, $storeMoneyFormat);
            $chars = strlen($textLine) + strlen($currencySymbol . $finalTotalFormatted);
            $separators = PrintJobHelper::spacesBetween($chars, $charsPerLine);

            array_push($job["instructions"], PrintJobHelper::generateInstruction(
                Command::PRINT_TEXT,
                $textLine . $separators . $currencySymbol . $finalTotalFormatted
            ));
        }
    }
    public  static function formatNumberToMoney($number, $storeMoneyFormat)
    {
        //Formatea un numero en función del parametro de moneda asiganado en BD, en la tabla store_configs
        if ($storeMoneyFormat->store_money_format == null) {
            $nations = array();
            $nation = new \stdClass();
            $nation->locale = "MX";
            $nation->nation =  "US";
            $nation->minimumFractionDigits = 2;
            $nation->maximumFractionDigits = 2;

            $nation1 = new \stdClass();
            $nation1->locale = "CO";
            $nation1->nation =  "de-DE";
            $nation1->minimumFractionDigits = 2;
            $nation1->maximumFractionDigits = 2;

            $nation2 = new \stdClass();
            $nation2->locale = "EC";
            $nation2->nation =  "US";
            $nation2->minimumFractionDigits = 2;
            $nation2->maximumFractionDigits = 2;

            array_push($nations, $nation1, $nation2);
            $encontrado = false;
            foreach ($nations as $key => $nation) {
                if ($nation->locale == $storeMoneyFormat->country) {
                    $storeMoneyFormat->store_money_format = $nation;
                }
            }
            if ($storeMoneyFormat->store_money_format == null) {
                $storeMoneyFormat->store_money_format = $nation1;
            }
        }
        $storeMoneyConfiguration = $storeMoneyFormat->store_money_format;
        $separatorMiles = PrintJobHelper::setSeparatorThousands($storeMoneyConfiguration->locale);
        $separatorRegex = PrintJobHelper::setSeparatorRegex($storeMoneyConfiguration->locale);

        $formatter = new \NumberFormatter($storeMoneyConfiguration->nation, \NumberFormatter::CURRENCY);
        $formatter->setSymbol(\NumberFormatter::CURRENCY_SYMBOL, '');
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $storeMoneyConfiguration->minimumFractionDigits);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $storeMoneyConfiguration->maximumFractionDigits);
        $formattedNumber = $formatter->format($number);
        $last = strrpos($formattedNumber, $separatorMiles);
        $butLast =  str_replace($separatorRegex, "'", substr($formattedNumber, 0, $last));
        $finalNumber = $butLast . substr($formattedNumber, $last);

        $finalNumber = preg_replace( '/[^0-9$.,\']/', '', $finalNumber);
        return $finalNumber;
    }
    public  static function setSeparatorThousands($country)
    {
        switch ($country) {
            case 'MX':
                return ',';
            case 'CO':
                return '.';
            default:
                return '.';
        }
    }
    public  static function setSeparatorRegex($country)
    {
        switch ($country) {
            case 'MX':
                return ',';
            case 'CO':
                return '.';
            default:
                return '.';
        }
    }
}
