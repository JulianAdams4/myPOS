<!DOCTYPE html>
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <title>Reporte Corte Z</title>
        <style>
            body {
                -webkit-text-size-adjust: 100% !important;
                -ms-text-size-adjust: 100% !important;
                -webkit-font-smoothing: antialiased !important;
            }
            img {
                border: 0 !important;
                outline: none !important;
            }
            p {
                Margin: 0px !important;
                Padding: 0px !important;
            }
            .table-content {
                width: 100%;
                border-spacing: 0px;
                padding: 0px;
                border: 0px;
                text-align: center;
            }
            .table-data-content {
                width: 100%;
                border: 0px;
                text-align: center;
            }
            .text-content {
                font-family: 'Open Sans', Arial, sans-serif;
                font-size: 17px;
                line-height: 22px;
                color: #2B3E50;
                text-align: left;
            }
            .text-header-content {
                font-family: 'Open Sans', Arial, sans-serif;
                font-size: 17px;
                font-weight: bold;
                line-height: 24px;
                color: #4453e2;
                text-transform: uppercase;
                text-align: center;
            }
            .separator {
                font-size: 0px;
                line-height: 0px;
                background-color: #DBDBDB;
                height: 1px;
            }
            .double-column {
                width: 49.79%%;
                vertical-align: text-top;
                display: inline-block;
            }
        </style>
    </head>
    <body style="margin:0px; padding:0px;">
        <table class="table-content">
            <tbody>
                <tr> <!-- === HEADER === -->
                    <td class="table-data-content">
                        <table class="table-content" style="table-layout: fixed;">
                            <tbody>
                                <tr>
                                    <td class="table-data-content">
                                        <img style="display: block; font-family: Arial, sans-serif; font-size: 15px; line-height: 18px; color: #30373b; font-weight: bold;" src="https://xxx.png" alt="myPOSLogo" height="50" />
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-content" style="font-size: 18px; text-transform: uppercase; text-align: center; font-weight: bold;">
                                        {{ $storeName }}
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="separator">
                        &nbsp;
                    </td>
                </tr>

                <tr> <!-- === INFO GENERAL === -->
                    <td class="table-data-content">
                        <table class="table-content" style="table-layout: fixed; padding-left: 16px; padding-right: 16px;">
                            <tbody>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content">
                                        <span>
                                            Corte Z #{{ $data["cashier_number"] }}
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Fecha apertura:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            {{ $data["date_open"] }} {{ $data["hour_open"] }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Fecha cierre:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            {{ $data["date_close"] }} {{ $data["hour_close"] }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Rango de tickets:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            {{ $extra_data["first_invoice_number"] }} &nbsp;a&nbsp; {{ $extra_data["last_invoice_number"] }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            N&uacute;mero de transacciones:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            {{ $extra_data["count_orders"] }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Tipo cambio d&oacute;lares:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            {{ number_format($conversion / 100, 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="separator">
                        &nbsp;
                    </td>
                </tr>

                <tr> <!-- === RESUMEN VALORES === -->
                    <td class="table-data-content">
                        <table class="table-content" style="table-layout: fixed; padding-left: 16px; padding-right: 16px;">
                            <tbody>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content">
                                        <span>
                                            Resumen de valores
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Cajero en apertura:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            {{ $employee_name_open }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Cajero en cierre:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            {{ $employee_name_close }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Saldo de apertura:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_open'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Total de ventas:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_sales'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Comandas por facturar:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_pending_orders'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Ventas generales:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_sales'] + $data['value_pending_orders'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Ordenes canceladas:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_revoked_orders'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="separator">
                        &nbsp;
                    </td>
                </tr>

                <tr> <!-- === TOTAL PAGOS === -->
                    <td class="table-data-content">
                        <table class="table-content" style="table-layout: fixed; padding-left: 16px; padding-right: 16px;">
                            <tbody>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content">
                                        <span>
                                            Total de pagos recibidos
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Efectivo ({{ $data['count_orders_cash'] }}):
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_cash'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Tarjetas ({{ $data['count_orders_card'] }}):
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_card'] + $data['value_tip_card'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Rappi Pay ({{ $data['count_orders_rappi_pay'] }}):
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_rappi_pay'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                @foreach($data['externalValues'] as $value)
                                    <tr>
                                        <td>
                                            <span class="text-content double-column">
                                                {{ $value[0] }} ({{ $data["count_orders_external"][$value[0]] }}):
                                            </span>
                                            <span class="text-content double-column" style="text-align: right;">
                                                ${{ number_format($value[1] / 100, 2, '.', ',') }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Total recibido:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_sales'] + $data['value_tip_card'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Tot. Efectivo y Tarj.:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_cash'] + $data['value_card'] + $data['value_tip_card'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Total efectivo:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_cash'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <b>
                                            <span class="text-content double-column" style="width: 49.19%;">
                                                Saldo de apertura:
                                            </span>
                                        </b>
                                        <b>
                                            <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                                ${{ number_format($data['value_open'], 2, '.', ',') }}
                                            </span>
                                        </b>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <b>
                                            <span class="text-content double-column" style="width: 49.19%;">
                                                Efectivo en caja:
                                            </span>
                                        </b>
                                        <b>
                                            <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                                ${{ number_format($data['value_open'] + $data['value_cash'], 2, '.', ',') }}
                                            </span>
                                        </b>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <b>
                                            <span class="text-content double-column" style="width: 49.19%;">
                                                Gastos:
                                            </span>
                                        </b>
                                        <b>
                                            <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                                ${{ number_format($data['total_expenses'], 2, '.', ',') }}
                                            </span>
                                        </b>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <b>
                                            <span class="text-content double-column" style="width: 49.19%;">
                                                Propinas a tarjetas:
                                            </span>
                                        </b>
                                        <b>
                                            <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                                ${{ number_format($data['value_tip_card'], 2, '.', ',') }}
                                            </span>
                                        </b>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <b>
                                            <span class="text-content double-column" style="width: 49.19%;">
                                                Propinas a efectivo:
                                            </span>
                                        </b>
                                        <b>
                                            <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                                ${{ number_format($data['value_tip_cash'], 2, '.', ',') }}
                                            </span>
                                        </b>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <b>
                                            <span class="text-content double-column" style="width: 49.19%;">
                                                Total a entregar a efectivo:
                                            </span>
                                        </b>
                                        <b>
                                            <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                                ${{ number_format($data['value_open'] + $data['value_cash'] - $data['value_tip_card'] - $data['total_expenses'], 2, '.', ',') }}
                                            </span>
                                        </b>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="separator">
                        &nbsp;
                    </td>
                </tr>

                <tr> <!-- === DATOS ESTADISTICOS === -->
                    <td class="table-data-content">
                        <table class="table-content" style="table-layout: fixed; padding-left: 16px; padding-right: 16px;">
                            <tbody>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content">
                                        <span>
                                            DATOS ESTADISTICOS
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content" style="color: #1d262c; text-align: left;">
                                        <span>
                                            VENTAS SERVICIO EN MESAS
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            N&uacute;mero de clientes:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            {{ $extra_data['customer_local'] }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Mesas atendidas:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            {{ $extra_data['count_local_orders'] }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            Promedio por mesa:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format(($data['value_sales'] - $data['value_deliveries']) / $extra_data['count_local_orders'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Monto venta total:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_sales'] - $data['value_deliveries'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Promedio por cliente:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format(($data['value_sales'] - $data['value_deliveries']) / $extra_data['customer_local'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content" style="color: #1d262c; text-align: left;">
                                        <span>
                                            VENTAS SERVICIO DELIVERY
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            N&uacute;mero de clientes:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            {{ $extra_data['customer_delivery'] }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Monto venta total:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_deliveries'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Promedio por cliente:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_deliveries'] / $extra_data['customer_delivery'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="separator">
                        &nbsp;
                    </td>
                </tr>

                <tr> <!-- === IMPUESTOS Y PRODUCTOS === -->
                    <td class="table-data-content">
                        <table class="table-content" style="table-layout: fixed; padding-left: 16px; padding-right: 16px;">
                            <tbody>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content">
                                        <span>
                                            Impuestos y productos
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content" style="color: #1d262c; text-align: left;">
                                        <span>
                                            Totales Tasas (Sin valor de la tasa)
                                        </span>
                                    </td>
                                </tr>
                                @foreach($extra_data['tax_values_details'] as $detail)
                                    <tr>
                                        <td>
                                            <span class="text-content double-column">
                                                TASA {{ $detail["percentage"] }}%
                                            </span>
                                            <span class="text-content double-column" style="text-align: right;">
                                                ${{ number_format(round($detail['total'] / 100, 2), 2, '.', ',') }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td class="text-header-content" style="color: #1d262c; text-align: left;">
                                        <span>
                                            Impuestos y Totales
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Bebidas:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($extra_data['drink_sutotal'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Alimentos:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($extra_data['food_sutotal'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            Otros art&iacute;culos:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($extra_data['other_sutotal'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <b>
                                            <span class="text-content double-column" style="width: 49.19%;">
                                                Subtotal:
                                            </span>
                                        </b>
                                        <b>
                                            <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                                ${{ number_format($extra_data['subtotal_no_tax'], 2, '.', ',') }}
                                            </span>
                                        </b>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <b>
                                            <span class="text-content double-column" style="width: 49.19%;">
                                                IVA:
                                            </span>
                                        </b>
                                        <b>
                                            <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                                ${{ number_format($extra_data['food_tax'] + $extra_data['drink_tax'] + $extra_data['other_tax'], 2, '.', ',') }}
                                            </span>
                                        </b>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <b>
                                            <span class="text-content double-column" style="width: 49.19%;">
                                                Totales:
                                            </span>
                                        </b>
                                        <b>
                                            <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                                ${{ number_format($extra_data['food_total'] + $extra_data['drink_total'] + $extra_data['other_total'], 2, '.', ',') }}
                                            </span>
                                        </b>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <b>
                                            <span class="text-content double-column" style="width: 49.19%;">
                                                Descuentos:
                                            </span>
                                        </b>
                                        <b>
                                            <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                                ${{ number_format($extra_data['total_discount'], 2, '.', ',') }}
                                            </span>
                                        </b>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            Totales:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($extra_data['food_total'] + $extra_data['drink_total'] + $extra_data['other_total'] - $extra_data['total_discount'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            Propinas a tarjetas:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($data['value_tip_card'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            Propinas a efectivo:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($data['value_tip_cash'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            Total general:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($extra_data['food_total'] + $extra_data['drink_total'] + $extra_data['other_total'] - $extra_data['total_discount'] + $data['value_tip_card'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content" style="color: #1d262c; text-align: left;">
                                        <span>
                                            Bebidas
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            Neto:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($extra_data['drink_sutotal'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            IVA:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($extra_data['drink_tax'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            Descuento:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($extra_data['total_discount'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content" style="color: #1d262c; text-align: left;">
                                        <span>
                                            Alimentos
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            Neto:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($extra_data['food_sutotal'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            IVA:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($extra_data['food_tax'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            Descuento:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($extra_data['total_discount'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content" style="color: #1d262c; text-align: left;">
                                        <span>
                                            Otros articulos
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            Neto:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($extra_data['other_sutotal'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            IVA:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($extra_data['other_tax'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column" style="width: 49.19%;">
                                            Descuento:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right; width: 49.19%;">
                                            ${{ number_format($extra_data['total_discount'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="separator">
                        &nbsp;
                    </td>
                </tr>

                <tr> <!-- === Desglose de tarjetas === -->
                    <td class="table-data-content">
                        <table class="table-content" style="table-layout: fixed; padding-left: 16px; padding-right: 16px;">
                            <tbody>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content">
                                        <span>
                                            Desglose de tarjetas
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                @foreach($extra_data['card_details_payments'] as $cardDetail)
                                    @if (count($cardDetail["transactions"]) > 0)
                                        <tr>
                                            <td class="text-header-content" style="color: #1d262c; text-align: left;">
                                                <span>
                                                    {{ $cardDetail["name"] }}
                                                </span>
                                            </td>
                                        </tr>
                                        @foreach($cardDetail["transactions"] as $transaction)
                                            <tr>
                                                <td>
                                                    <span class="text-content double-column">
                                                        {{ is_null($transaction["last_digits"]) ? "N/A" : $transaction["last_digits"] }}
                                                    </span>
                                                    <span class="text-content double-column" style="text-align: right;">
                                                        F: {{ $transaction["invoice_number"] }}
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <span class="text-content double-column">
                                                        Valor: $ {{ number_format($transaction["value"], 2, '.', ',') }}
                                                    </span>
                                                    <span class="text-content double-column" style="text-align: right;">
                                                        Propina: $ {{ number_format($transaction["tip"], 2, '.', ',') }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    @endif
                                @endforeach
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Total tarjetas:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_card'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Total propinas:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['value_tip_card'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="separator">
                        &nbsp;
                    </td>
                </tr>

                <tr> <!-- === Detalle de Ventas y Propinas === -->
                    <td class="table-data-content">
                        <table class="table-content" style="table-layout: fixed; padding-left: 16px; padding-right: 16px;">
                            <tbody>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content">
                                        <span>
                                            Detalle de ventas y propinas
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                @foreach($extra_data['employee_details_transactions'] as $transactionsDetail)
                                    @if($transactionsDetail["value"] > 0 && $transactionsDetail["name"] != "Integración")
                                        <tr>
                                            <td>
                                                <span class="text-content double-column">
                                                    Nombre:
                                                </span>
                                                <span class="text-content double-column" style="text-align: right;">
                                                    {{ $transactionsDetail["name"] }}
                                                </span>
                                            </td>
                                        </tr>
                                        <tr style="height: 8px;">
                                            <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                                &nbsp;
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <span class="text-content double-column">
                                                    Total de ventas:
                                                </span>
                                                <span class="text-content double-column" style="text-align: right;">
                                                    ${{ number_format(round($transactionsDetail["value"]/100, 2), 2, '.', ',') }}
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <span class="text-content double-column">
                                                    Propinas:
                                                </span>
                                                <span class="text-content double-column" style="text-align: right;">
                                                    ${{ number_format(round($transactionsDetail["tips"]/100, 2), 2, '.', ',') }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="separator">
                        &nbsp;
                    </td>
                </tr>

                <tr> <!-- === Gastos === -->
                    <td class="table-data-content">
                        <table class="table-content" style="table-layout: fixed; padding-left: 16px; padding-right: 16px;">
                            <tbody>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content">
                                        <span>
                                            Gastos
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                @foreach($data['expenses'] as $expense)
                                    <tr>
                                        <td>
                                            <span class="text-content double-column">
                                                {{ $expense["name"] }}
                                            </span>
                                            <span class="text-content double-column" style="text-align: right;">
                                                ${{ number_format($expense['value'] / 100, 2, '.', ',') }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Totales:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($data['total_expenses'], 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td class="separator">
                        &nbsp;
                    </td>
                </tr>

                <tr> <!-- === Detalle de Creditos a Clientes === -->
                    <td class="table-data-content">
                        <table class="table-content" style="table-layout: fixed; padding-left: 16px; padding-right: 16px;">
                            <tbody>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-header-content">
                                        <span>
                                            Detalle de cr&eacute;ditos a clientes
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                                @php
                                    $total = 0;
                                @endphp
                                @foreach($extra_data['rappi_values_details'] as $transactionsDetail)
                                    @php
                                        $total += $transactionsDetail['value'];
                                    @endphp
                                    <tr>
                                        <td>
                                            <span class="text-content double-column">
                                                {{ $transactionsDetail["name"] }} (F: {{ $transactionsDetail["invoice_number"] }}):
                                            </span>
                                            <span class="text-content double-column" style="text-align: right;">
                                                ${{ number_format($transactionsDetail['value'], 2, '.', ',') }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td>
                                        <span class="text-content double-column">
                                            Totales:
                                        </span>
                                        <span class="text-content double-column" style="text-align: right;">
                                            ${{ number_format($total, 2, '.', ',') }}
                                        </span>
                                    </td>
                                </tr>
                                <tr style="height: 8px;">
                                    <td style="font-size: 1px; line-height: 1px; height: 8px;">
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td class="separator">
                        &nbsp;
                    </td>
                </tr>
            </tbody>
        </table>

    </body>
</html>