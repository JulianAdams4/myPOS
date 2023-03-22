<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AvailableMyposIntegration extends Model
{
    public $fillable = ['id','type','code_name','name','anton_integration'];

    // Constantes del nombre integración delivery
    const NAME_EATS = 'uber_eats';
    const NAME_RAPPI = 'rappi';
    const NAME_POSTMATES = 'postmates';
    const NAME_SIN_DELANTAL = 'sin_delantal';
    const NAME_DOMICILIOS = 'domicilios.com';
    const NAME_IFOOD = 'ifood';
    const NAME_RAPPI_ANTOJO = 'rappi_antojo';
    const NAME_RAPPI_PICKUP = 'rappi_pickup';
    const NAME_DIDI = 'didi';
    const NAME_RAPPI_PAY = 'rappi_pay';
    const NAME_NORMAL = 'local';
    const NAME_DIVIDIR = 'local';
    const NAME_KIOSKO = 'kiosko';
    const NAME_MENIU = 'meniu';
    const NAME_DELIVERY = 'delivery';
    const NAME_GROUPON = 'groupon';
    const NAME_LETS_EAT = 'lets_eat';
    const NAME_FINGER_FOOD = "finder_food";
    const NAME_RAPPI_PAY_KIOSKO = "rappi_pay_kiosko";
    const NAME_MELY = "mely";
    const NAME_WOMPI = "wompi";
    const NAME_CLIENTES_CORPORATIVOS = "clientes_corporativos";
    const NAME_MERCADO_PAGO = 'mercado_pago';
    const NAME_BONOS = 'bonos_sodexo_bigpass';
    const NAME_EXITO = 'exito';

    // Constantes del nombre integración POS
    const NAME_ALOHA = 'aloha';
    const NAME_SIIGO = 'siigo';
    const NAME_FACTURAMA = 'facturama';

    const AVAILABLE_RAPPI_PAY_COUNTRIES = ["CO", "MX"];
}
