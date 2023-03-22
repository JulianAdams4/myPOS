<?php
namespace App\Helpers\PrintService;

use Log;
use App\Helper;
use App\Invoice;
use App\StoreConfig;
use App\StorePrinter;

use Mike42\Escpos\Printer;
use App\Events\PrintJobEvent;
use App\Traits\TimezoneHelper;

use App\Helpers\PrintService\Command;
use App\Helpers\PrintService\PrintJobHelper;
use App\Helpers\PrintService\ProtocolCommand;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// @codingStandardsIgnoreLine
abstract class PrinterConnector
{
    const WINDOWS = 1;
    const LINUX = 2;
    const IP = 3;
    const USB = 4;
}

// @codingStandardsIgnoreLine
abstract class PrinterAction
{
    const INVOICE = 1;
    const COMANDA = 2;
    const PREINVOICE = 3;
    const CASHIER_REPORT = 4;
    const CHECK_IN = 5;
    const XZ_REPORT = 6;
}

const MAX_LENGTH = 12;
const CHARS_PER_LINE = 44;

const COMPATIBLE_PRINTERS = array(
    "TM-T20II"
);

/**
 * Expose functions to print comanda, pre-invoice and invoice
 */
// @codingStandardsIgnoreLine
class PrintServiceHelper
{

    public static function dispatchJobs($jobs)
    {
        if ($jobs == null || empty($jobs)) {
            return;
        }

        $rabbitHost = config('app.rabbitmq_host');
        $rabbitPort = config('app.rabbitmq_port');
        $rabbitUser = config('app.rabbitmq_username');
        $rabbitPswd = config('app.rabbitmq_password');
        $rabbitVhost = config('app.rabbitmq_vhost');
    
        $connection = new AMQPStreamConnection($rabbitHost, $rabbitPort, $rabbitUser, $rabbitPswd, $rabbitVhost);
        $channel = $connection->channel();
        $channel->exchange_declare('print-service', 'fanout', false, false, false);

        foreach ($jobs as $job) {
            event(new PrintJobEvent($job));
            $msg = new AMQPMessage(json_encode($job));
            $channel->batch_basic_publish($msg, 'print-service');
        }

        $channel->publish_batch();
        $channel->close();
        $connection->close();
    }

    public static function printComanda($order, $employee)
    {
        $jobs = PrintServiceHelper::getComandaJobs($order, $employee);

        PrintServiceHelper::dispatchJobs($jobs);
    }

    public static function printPreInvoice($order, $employee, $request)
    {
        $jobs = PrintServiceHelper::getPreInvoiceJobs($order, $employee, $request);

        PrintServiceHelper::dispatchJobs($jobs);
    }

    public static function printInvoice(Invoice $invoice, $employee, $isRevoked = false)
    {
        $jobs = PrintServiceHelper::getInvoiceJobs($invoice, $employee, $isRevoked);

        PrintServiceHelper::dispatchJobs($jobs);
    }

    public static function printCashierReport($data, $employee)
    {
        $jobs = PrintServiceHelper::getCashierReportJobs($data, $employee);

        PrintServiceHelper::dispatchJobs($jobs);
    }

    public static function printCheckin($isCheckin, $employee)
    {
        $jobs = PrintServiceHelper::getCheckinJobs($isCheckin, $employee);

        PrintServiceHelper::dispatchJobs($jobs);
    }

    public static function printXZReport($data, $employee, $type, $extraData)
    {
        $jobs = PrintServiceHelper::getXZJobs($data, $employee, $type, $extraData);

        PrintServiceHelper::dispatchJobs($jobs);
    }

    public static function getComandaJobs($order, $employee)
    {
        $store = $employee->store;

        $orderDetails = $order->orderDetails;
        $isReprint = PrintJobHelper::isReprint($orderDetails);
        $groupedDetails = PrintJobHelper::groupDetailsByPrinter($orderDetails, $store->printers, $isReprint);

        $rawPrinterIds = array_keys($groupedDetails);
        $rawPrinters = StorePrinter::whereIn('id', $rawPrinterIds)
                            ->where('actions', PrinterAction::COMANDA)->get();
        $instructions = array();
        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        $comandaInstructions = json_decode($storeConfig->comanda);
        $storeMoneyFormat= new \stdClass();
        $storeMoneyFormat->store_money_format= json_decode($storeConfig->store_money_format);
        $storeMoneyFormat->country=$store->country_code;

        $jobs = array();

        foreach ($rawPrinters as $rawPrinter) {
            $fetchedOrderDetails = $groupedDetails[$rawPrinter->id];
            foreach ($fetchedOrderDetails as $orderDetail) {
                $orderDetail->append('spec_fields');
            }
            $groupedDetailKey = Helper::getDetailsUniqueGroupedByCompoundKey(
                collect($groupedDetails[$rawPrinter->id])
            );

            try {
                $job = array();
                $job["printer_id"] = $rawPrinter->id;
                $job["printer_connector"] = $rawPrinter->connector;
                $job["printer_interface"] = $rawPrinter->interface;
                $job["store_id"] = $store->id;
                $job["instructions"] = array();

                $integrationName = "";
                $printLogo = false;
                $spot = $order->spot->append('name_integration');

                foreach ($comandaInstructions as $instruction) {
                    switch ($instruction->cmd) {
                        case ProtocolCommand::PRINT_PRODUCTS_HEADER:
                            PrintJobHelper::handlePrintProductsHeader(
                                $instruction->payload,
                                $job,
                                $order->identifier,
                                $isReprint
                            );
                            break;
                        case ProtocolCommand::PRINT_PRODUCTS:
                            PrintJobHelper::handlePrintComandaProducts(
                                $rawPrinter,
                                $instruction->payload,
                                $job,
                                $groupedDetailKey,
                                $storeConfig->currency_symbol,
                                $storeMoneyFormat
                            );
                            break;
                        default:
                            PrintJobHelper::handleJsonInstruction($instruction, $rawPrinter, $job, $order, $employee);
                            break;
                    }
                }

                foreach ($fetchedOrderDetails as $orderDetail) {
                    PrintJobHelper::markAsPrinted($orderDetail);
                }

                array_push($jobs, $job);
            } catch (\Exception $e) {
                Log::info("PrintServiceHelper: NO SE PUDO IMPRIMIR LA COMANDA");
                Log::info($e->getMessage());
                Log::info("Archivo");
                Log::info($e->getFile());
                Log::info("Línea");
                Log::info($e->getLine());
                Log::info("Provocado por");
                Log::info(json_encode($order));
            }
        }

        return $jobs;
    }

    public static function getPreInvoiceJobs($order, $employee, $request)
    {
        $store = $employee->store;

        if (!$store->printers) {
            return;
        }

        foreach ($order->orderDetails as $detail) {
            $detail->append('spec_fields');
        }

        $groupedOrderDetails = Helper::getDetailsUniqueGroupedByCompoundKey($order->orderDetails);

        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        $currencySymbol = $storeConfig->currency_symbol;
        $storeMoneyFormat= new \stdClass();
        $storeMoneyFormat->store_money_format= json_decode($storeConfig->store_money_format);
        $storeMoneyFormat->country=$store->country_code;
        $preInvoiceInstructions = json_decode($storeConfig->precuenta);
       
        $jobs = array();

        foreach ($store->printers as $printer) {
            if ($printer->actions != PrinterAction::PREINVOICE) {
                continue;
            }

            // If locations are defined, skip printers that don't match with employee
            if ($printer->store_locations_id != null
            && $employee->location != null
            && $printer->store_locations_id != $employee->location->id) {
                continue;
            }

            try {
                $job = array();
                $job["printer_id"] = $printer->id;
                $job["printer_connector"] = $printer->connector;
                $job["printer_interface"] = $printer->interface;
                $job["store_id"] = $store->id;
                $job["instructions"] = array();

                foreach ($preInvoiceInstructions as $instruction) {
                    switch ($instruction->cmd) {
                        case ProtocolCommand::PRINT_PRODUCTS_HEADER:
                            PrintJobHelper::handlePrintProductsHeader($instruction->payload, $job, $order->identifier);
                            break;
                        case ProtocolCommand::PRINT_PRODUCTS:
                            PrintJobHelper::handlePrintPreInvoiceProducts(
                                $printer,
                                $instruction->payload,
                                $job,
                                $groupedOrderDetails,
                                $currencySymbol,
                                $storeMoneyFormat
                            );
                            break;
                        case ProtocolCommand::PRINT_PRICE_SUMMARY:
                            PrintJobHelper::handlePrintPreInvoicePriceSummary(
                                $instruction->payload,
                                $job,
                                $store,
                                $request,
                                $currencySymbol,
                                $storeMoneyFormat
                            );
                            break;
                        default:
                            PrintJobHelper::handleJsonInstruction($instruction, $printer, $job, $order, $employee);
                            break;
                    }
                }

                array_push($jobs, $job);
            } catch (\Exception $e) {
                Log::info("Couldn't print to this printer: " . $e -> getMessage() . "\n");
            }
        }

        return $jobs;
    }

    public static function getInvoiceJobs(Invoice $invoice, $employee, $isRevoked = false)
    {
        $store = $employee->store;

        if (!$store->printers) {
            return;
        }

        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        $currencySymbol = $storeConfig->currency_symbol;
        $storeMoneyFormat= new \stdClass();
        $storeMoneyFormat->store_money_format= json_decode($storeConfig->store_money_format);
        $storeMoneyFormat->country=$store->country_code;
       
        if ($isRevoked) {
            $formatInstructions = json_decode($storeConfig->credit_format);
        } else {
            $formatInstructions = json_decode($storeConfig->factura);
        }

        $jobs = array();

        foreach ($store->printers as $printer) {
            if ($printer->actions != PrinterAction::INVOICE) {
                continue;
            }

            // If locations are defined, skip printers that don't match with employee
            if ($printer->store_locations_id != null
            && $employee->location != null
            && $printer->store_locations_id != $employee->location->id) {
                continue;
            }

            try {
                $job = array();
                $job["printer_id"] = $printer->id;
                $job["printer_connector"] = $printer->connector;
                $job["printer_interface"] = $printer->interface;
                $job["store_id"] = $store->id;
                $job["instructions"] = array();

                // Crear directorio si no existe
                if (!file_exists(public_path().'/logo_stores')) {
                    mkdir(public_path().'/logo_stores', 0777, true);
                }

                 //En el caso de que se encuentre impreso, se le adicionara la palabra REIMPRESION

                foreach ($formatInstructions as $instruction) {
                    switch ($instruction->cmd) {
                        case ProtocolCommand::PRINT_PRODUCTS_HEADER:
                            PrintJobHelper::handlePrintProductsHeader($instruction->payload, $job, $invoice->order->identifier, $invoice->was_printed);
                            break;
                        case ProtocolCommand::PRINT_PRODUCTS:
                            PrintJobHelper::handlePrintInvoiceProducts(
                                $printer,
                                $instruction->payload,
                                $job,
                                $store,
                                $invoice,
                                $currencySymbol,
                                $storeMoneyFormat
                            );
                            break;
                        case ProtocolCommand::PRINT_PRICE_SUMMARY:
                            PrintJobHelper::handlePrintInvoicePriceSummary(
                                $instruction->payload,
                                $job,
                                $invoice,
                                $currencySymbol,
                                $storeMoneyFormat
                            );
                            break;
                        case ProtocolCommand::PRINT_PAYMENT_METHOD:
                            PrintJobHelper::handlePrintPaymentMethod($instruction->payload, $job, $invoice, $currencySymbol,$storeMoneyFormat);
                            break;
                        case ProtocolCommand::PRINT_TEXT:
                            PrintJobHelper::handlePrintText($instruction->payload, $job, $invoice->order, $employee, $invoice);
                            break;
                        case ProtocolCommand::PRINT_TEXT_JUSTIFY:
                            PrintJobHelper::handlePrintTextJustify($instruction->payload, $job, $invoice->order, $employee, $invoice);
                            break;
                        default:
                            PrintJobHelper::handleJsonInstruction($instruction, $printer, $job, $invoice->order, $employee);
                            break;
                    }
                }

                //Se actualiza el estado was_printed a 1 para que se sepa que ya se imprimio
                if (!$invoice->was_printed) {
                    Invoice::where('id', $invoice->id)->update(['was_printed' => 1]);
                }

                array_push($jobs, $job);
            } catch (\Exception $e) {
                Log::info("PrintServiceHelper: NO SE PUDO IMPRIMIR LA FACTURA");
                Log::info($e->getMessage());
                Log::info("Archivo");
                Log::info($e->getFile());
                Log::info("Línea");
                Log::info($e->getLine());
                Log::info("Provocado por");
                Log::info(json_encode($invoice));
            }
        }

        return $jobs;
    }

    public static function getCashierReportJobs($data, $employee)
    {
        $store = $employee->store;

        if (!$store->printers) {
            return;
        }

        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        $currencySymbol = $storeConfig->currency_symbol;
        $cierreInstructions = json_decode($storeConfig->cierre);
        $storeMoneyFormat= new \stdClass();
        $storeMoneyFormat->store_money_format= json_decode($storeConfig->store_money_format);
        $storeMoneyFormat->country=$store->country_code;

        $jobs = array();

        foreach ($store->printers as $printer) {
            if ($printer->actions != PrinterAction::CASHIER_REPORT) {
                continue;
            }

            try {
                $job = array();
                $job["printer_id"] = $printer->id;
                $job["printer_connector"] = $printer->connector;
                $job["printer_interface"] = $printer->interface;
                $job["store_id"] = $store->id;
                $job["instructions"] = array();

                // Crear directorio si no existe
                if (!file_exists(public_path().'/logo_stores')) {
                    mkdir(public_path().'/logo_stores', 0777, true);
                }

                foreach ($cierreInstructions as $instruction) {
                    switch ($instruction->cmd) {
                        case ProtocolCommand::PRINT_CASHIER_SUMMARY:
                            PrintJobHelper::handlePrintCashierSummary($instruction->payload, $job, $employee, $data, $currencySymbol);
                            break;
                        case ProtocolCommand::PRINT_CASHIER_DETAILS:
                            PrintJobHelper::handlePrintCashierDetails($instruction->payload, $job, $data, $currencySymbol,$storeMoneyFormat);
                            break;
                        case ProtocolCommand::PRINT_SALES_SUMMARY:
                            PrintJobHelper::handlePrintSalesSummary($instruction->payload, $job, $data, $currencySymbol,$storeMoneyFormat);
                            break;
                        case ProtocolCommand::PRINT_SALES_DETAILS:
                            PrintJobHelper::handlePrintSalesDetails($instruction->payload, $job, $data, $currencySymbol,$storeMoneyFormat);
                            break;
                        default:
                            PrintJobHelper::handleJsonInstruction($instruction, $printer, $job, null, $employee);
                            break;
                    }
                }

                array_push($jobs, $job);
            } catch (\Exception $e) {
                Log::info("PrintServiceHelper: NO SE PUDO IMPRIMIR REPORTE DE CAJA");
                Log::info($e->getMessage());
                Log::info("Archivo");
                Log::info($e->getFile());
                Log::info("Línea");
                Log::info($e->getLine());
                Log::info("Provocado por");
                Log::info(json_encode($data));
            }
        }

        return $jobs;
    }

    public static function getCheckinJobs($isCheckin, $employee)
    {
        $store = $employee->store;

        if (!$store->printers) {
            return;
        }

        $checkinMessage = $isCheckin ? "Comprobante de ENTRADA" : "Comprobante de SALIDA";

        $now = TimezoneHelper::localizedNowDateForStore($store)->toDateTimeString();

        $jobs = array();

        foreach ($store->printers as $printer) {
            if ($printer->actions != PrinterAction::CHECK_IN) {
                continue;
            }

            try {
                $job = array();
                $job["printer_id"] = $printer->id;
                $job["printer_connector"] = $printer->connector;
                $job["printer_interface"] = $printer->interface;
                $job["store_id"] = $store->id;
                $job["instructions"] = array();

                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_MODE,
                    Printer::MODE_DOUBLE_HEIGHT
                ));

                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    $store->name
                ));

                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    $checkinMessage
                ));

                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    "\n" . $employee->name
                ));

                array_push($job["instructions"], PrintJobHelper::generateInstruction(
                    Command::PRINT_TEXT,
                    $now
                ));

                array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::FEED, 2));
                array_push($job["instructions"], PrintJobHelper::generateInstruction(Command::CUT, Printer::CUT_FULL));

                array_push($jobs, $job);
            } catch (\Exception $e) {
                Log::info("PrintServiceHelper: NO SE PUDO IMPRIMIR REPORTE DE CAJA");
                Log::info($e->getMessage());
                Log::info("Archivo");
                Log::info($e->getFile());
                Log::info("Línea");
                Log::info($e->getLine());
            }
        }

        return $jobs;
    }

    public static function getXZJobs($data, $employee, $type, $extraData)
    {
        $store = $employee->store;

        if (!$store->printers) {
            return null;
        }
        
        $storeConfig = StoreConfig::where('store_id', $store->id)->first();
        $currencySymbol = $storeConfig->currency_symbol;
        $XZInstructions = json_decode($storeConfig->xz_format);
        $storeMoneyFormat= new \stdClass();
        $storeMoneyFormat->store_money_format= json_decode($storeConfig->store_money_format);
        $storeMoneyFormat->country=$store->country_code;

        $jobs = array();

        foreach ($store->printers as $printer) {
            if ($printer->actions != PrinterAction::XZ_REPORT) {
                continue;
            }

            try {
                $job = array();
                $job["printer_id"] = $printer->id;
                $job["printer_connector"] = $printer->connector;
                $job["printer_interface"] = $printer->interface;
                $job["store_id"] = $store->id;
                $job["instructions"] = array();

                // Crear directorio si no existe
                if (!file_exists(public_path().'/logo_stores')) {
                    mkdir(public_path().'/logo_stores', 0777, true);
                }

                foreach ($XZInstructions as $instruction) {
                    switch ($instruction->cmd) {
                        case ProtocolCommand::PRINT_XZ_HEADER:
                            PrintJobHelper::handlePrintXZReportHeader(
                                $instruction->payload,
                                $job,
                                $employee,
                                $data,
                                $type,
                                $extraData,
                                $storeConfig->dollar_conversion
                            );
                            break;
                        case ProtocolCommand::PRINT_XZ_SUMMARY:
                            PrintJobHelper::handlePrintXZReportSummary(
                                $instruction->payload,
                                $job,
                                $employee,
                                $data,
                                $currencySymbol,
                                $type,
                                $storeMoneyFormat
                            );
                            break;
                        case ProtocolCommand::PRINT_XZ_PAYMENTS:
                            PrintJobHelper::handlePrintXZReportPaymentValues(
                                $instruction->payload,
                                $job,
                                $data,
                                $currencySymbol,
                                $storeMoneyFormat
                            );
                            break;
                        case ProtocolCommand::PRINT_XZ_EXPENSES:
                            PrintJobHelper::handlePrintExpensesValues(
                                $instruction->payload,
                                $job,
                                $data,
                                $currencySymbol,
                                $storeMoneyFormat
                            );
                            break;
                        case ProtocolCommand::PRINT_XZ_STATS:
                            PrintJobHelper::handlePrintXZReportStats(
                                $instruction->payload,
                                $job,
                                $data,
                                $currencySymbol,
                                $extraData,
                                $storeMoneyFormat
                            );
                            break;
                        case ProtocolCommand::PRINT_XZ_TAXES_TYPES:
                            PrintJobHelper::handlePrintXZReportTaxesAndTypes(
                                $instruction->payload,
                                $job,
                                $data,
                                $currencySymbol,
                                $extraData,
                                $storeMoneyFormat
                            );
                            break;
                        case ProtocolCommand::PRINT_XZ_CARD_DETAILS:
                            PrintJobHelper::handlePrintXZReportCardsDetails(
                                $instruction->payload,
                                $job,
                                $data,
                                $currencySymbol,
                                $extraData,
                                $storeMoneyFormat
                            );
                            break;
                        case ProtocolCommand::PRINT_XZ_EMPLOYEE_DETAILS:
                            PrintJobHelper::handlePrintXZReportEmployeeDetails(
                                $instruction->payload,
                                $job,
                                $data,
                                $currencySymbol,
                                $extraData,
                                $storeMoneyFormat
                            );
                            break;
                        case ProtocolCommand::PRINT_XZ_RAPPI_DETAILS:
                            PrintJobHelper::handlePrintXZReportRappiDetails(
                                $instruction->payload,
                                $job,
                                $data,
                                $currencySymbol,
                                $extraData,
                                $storeMoneyFormat
                            );
                            break;
                        default:
                            PrintJobHelper::handleJsonInstruction($instruction, $printer, $job, null, $employee);
                            break;
                    }
                }

                array_push($jobs, $job);
            } catch (\Exception $e) {
                Log::info("PrintServiceHelper: NO SE PUDO IMPRIMIR REPORTE XZ DE CAJA");
                Log::info($e->getMessage());
                Log::info("Archivo");
                Log::info($e->getFile());
                Log::info("Línea");
                Log::info($e->getLine());
                Log::info("Provocado por");
                Log::info(json_encode($data));
            }
        }

        return $jobs;
    }
}
