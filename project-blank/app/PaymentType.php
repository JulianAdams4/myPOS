<?php

namespace App;

// @codingStandardsIgnoreLine
abstract class PaymentType
{
    const CASH = 0;
    const DEBIT = 1;
    const CREDIT = 2;
    const TRANSFER = 3;
    const OTHER = 4;
    const RAPPI_PAY = 5;
    const SODEXHO_PASS = 6;
    const QPASS_PRODUCTO = 7;
    const QPASS_VALOR = 8;
    const QPASS_LOCAL = 9;
    const BIG_PASS = 10;
    const CREDITO_EMPLEADOS = 11;
    const CREDITO_FRANQUICIA = 12;
    const CREDITO_CLIENTES = 13;
    const BONOS_CENTROS = 14;
    const CUPONES = 15;
    const DEVOLUCION_EFECTIVO = 16;
    const CHEQUE = 17;
    const DESCUENTOS = 18;
    const QPASS_FALABELLA = 19;
    const PLAZES = 20;
    const VISA_PANAMA_US = 21;
    const MASTERCARD_PANAMA_USD = 22;
    const SISTEMA_CLAVE_PANAMA_USD = 23;
    const QPASS_CAMPO_SANTO = 24;
    const BONO_PEPE_GANGA = 25;
    const QPASS_POLUX = 26;
    const BONO_COOMEVA = 27;
    const QPASS_ELECTRONICO = 28;
    const NORMAL_QPASS = 29;
    const BONO_REDEBAN_50 = 30;
    const BONO_REDEBAN_20 = 31;
    const BONO_PROMOCION = 32;
    const CREDITO_RAPPI = 33;
    const BONO_DOMICILIO = 34;
    const PAGOS_ONLINE = 35;
    const RAPICREDITO = 36;
    const ONLINE_IFOOD = 37;
    const TARJETAS_AUTO = 38;
    const BONO_QUANTUM = 39;
    const MUSIQ = 40;
    const CREDITO_UBEREATS = 41;
    const UBER_EATS = 42;
    const FUERZAS_MILITARES = 43;
    const PROMOCION_BILLETE = 44;
    const CUPON_IFOOD = 45;
    const WOMPI = 46;
    const CLIENTES_CORPORATIVOS = 47;
    const MERCADO_PAGO = 48;
}