<?php

namespace App;

use App\Traits\TimezoneHelper;
use Illuminate\Database\Eloquent\Model;
use App\Events\CompanyPaymentCreatedEvent;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'type',
        'total',
        'created_at'
    ];

    protected $hidden = [
        'updated_at'
    ];

    protected $casts = [
        'total' => 'float'
    ];

    public function order()
    {
        return $this->belongsTo('App\Order');
    }

    public function typeName()
    {
        $type = $this->attributes['type'];
        $name = $this->getNameByType($type);
        return $name;
    }

    public static function getNameByType($type)
    {
        $name = '';

        switch ($type) {
            case PaymentType::CASH:
                $name = 'Efectivo';
                break;
            case PaymentType::DEBIT:
                $name = 'Débito';
                break;
            case PaymentType::CREDIT:
                $name = 'Crédito';
                break;
            case PaymentType::TRANSFER:
                $name = 'Transferencia';
                break;
            case PaymentType::RAPPI_PAY:
                $name = 'RappiPay';
                break;
            case PaymentType::OTHER:
                $name = 'Otro';
                break;
            case PaymentType::SODEXHO_PASS:
                $name = 'Sodexho_pass';
                break;
            case PaymentType::QPASS_PRODUCTO:
                $name = 'Qpass_producto';
                break;
            case PaymentType::QPASS_VALOR:
                $name = 'Qpass_valor';
                break;
            case PaymentType::QPASS_LOCAL:
                $name = 'Qpass_local';
                break;
            case PaymentType::BIG_PASS:
                $name = 'Big_pass';
                break;
            case PaymentType::CREDITO_EMPLEADOS:
                $name = 'Credito_empleados';
                break;
            case PaymentType::CREDITO_FRANQUICIA:
                $name = 'Credito_franquicia';
                break;
            case PaymentType::CREDITO_CLIENTES:
                $name = 'Credito_clientes';
                break;
            case PaymentType::BONOS_CENTROS:
                $name = 'Bonos_centros';
                break;
            case PaymentType::CUPONES:
                $name = 'Cupones';
                break;
            case PaymentType::DEVOLUCION_EFECTIVO:
                $name = 'Devolucion_efectivo';
                break;
            case PaymentType::CHEQUE:
                $name = 'Cheque';
                break;
            case PaymentType::DESCUENTOS:
                $name = 'Descuentos';
                break;
            case PaymentType::QPASS_FALABELLA:
                $name = 'Qpass_falabella';
                break;
            case PaymentType::PLAZES:
                $name = 'Plazes';
                break;
            case PaymentType::VISA_PANAMA_US:
                $name = 'Visa_panama_us';
                break;
            case PaymentType::MASTERCARD_PANAMA_USD:
                $name = 'Mastercard_panama_usd';
                break;
            case PaymentType::SISTEMA_CLAVE_PANAMA_USD:
                $name = 'Sistema_clave_panama_usd';
                break;
            case PaymentType::QPASS_CAMPO_SANTO:
                $name = 'Qpass_campo_santo';
                break;
            case PaymentType::BONO_PEPE_GANGA:
                $name = 'Bono_pepe_ganga';
                break;
            case PaymentType::QPASS_POLUX:
                $name = 'Qpass_polux';
                break;
            case PaymentType::BONO_COOMEVA:
                $name = 'Bono_coomeva';
                break;
            case PaymentType::QPASS_ELECTRONICO:
                $name = 'Qpass_electronico';
                break;
            case PaymentType::NORMAL_QPASS:
                $name = 'Normal_qpass';
                break;
            case PaymentType::BONO_REDEBAN_50:
                $name = 'Bono_redeban_50';
                break;
            case PaymentType::BONO_REDEBAN_20:
                $name = 'Bono_redeban_20';
                break;
            case PaymentType::BONO_PROMOCION:
                $name = 'Bono_promocion';
                break;
            case PaymentType::CREDITO_RAPPI:
                $name = 'Credito_rappi';
                break;
            case PaymentType::BONO_DOMICILIO:
                $name = 'Bono_domicilio';
                break;
            case PaymentType::PAGOS_ONLINE:
                $name = 'PAGOS_ONLINE';
                break;
            case PaymentType::RAPICREDITO:
                $name = 'Rapicredito';
                break;
            case PaymentType::ONLINE_IFOOD:
                $name = 'Online_ifood';
                break;
            case PaymentType::TARJETAS_AUTO:
                $name = 'Tarjetas_auto';
                break;
            case PaymentType::BONO_QUANTUM:
                $name = 'Bono_quantum';
                break;
            case PaymentType::MUSIQ:
                $name = 'Musiq';
                break;
            case PaymentType::CREDITO_UBEREATS:
                $name = 'Credito_ubereats';
                break;
            case PaymentType::UBER_EATS:
                $name = 'Uber_eats';
                break;
            case PaymentType::FUERZAS_MILITARES:
                $name = 'Fuerzas_militares';
                break;
            case PaymentType::PROMOCION_BILLETE:
                $name = 'Promocion_billete';
                break;
            case PaymentType::CUPON_IFOOD:
                $name = 'Cupon_ifood';
                break;
            case PaymentType::WOMPI:
                $name = 'Wompi';
                break;
            case PaymentType::CLIENTES_CORPORATIVOS:
                $name = 'Clientes_corporativos';
            case PaymentType::MERCADO_PAGO:
                $name = 'Mercado_pago';
                break;
        }

        return $name;
    }

    public static function gettypeNameByCode($type)
    {
        //$type = $this->attributes['type'];

        switch ($type) {
            case PaymentType::CASH:
                $name = 'Efectivo';
                break;
            case PaymentType::DEBIT:
                $name = 'Débito';
                break;
            case PaymentType::CREDIT:
                $name = 'Crédito';
                break;
            case PaymentType::TRANSFER:
                $name = 'Transferencia';
                break;
            case PaymentType::RAPPI_PAY:
                $name = 'RappiPay';
                break;
            case PaymentType::OTHER:
                $name = 'Otro';
                break;
            case PaymentType::SODEXHO_PASS:
                $name = 'Sodexho_pass';
                break;
            case PaymentType::QPASS_PRODUCTO:
                $name = 'Qpass_producto';
                break;
            case PaymentType::QPASS_VALOR:
                $name = 'Qpass_valor';
                break;
            case PaymentType::QPASS_LOCAL:
                $name = 'Qpass_local';
                break;
            case PaymentType::BIG_PASS:
                $name = 'Big_pass';
                break;
            case PaymentType::CREDITO_EMPLEADOS:
                $name = 'Credito_empleados';
                break;
            case PaymentType::CREDITO_FRANQUICIA:
                $name = 'Credito_franquicia';
                break;
            case PaymentType::CREDITO_CLIENTES:
                $name = 'Credito_clientes';
                break;
            case PaymentType::BONOS_CENTROS:
                $name = 'Bonos_centros';
                break;
            case PaymentType::CUPONES:
                $name = 'Cupones';
                break;
            case PaymentType::DEVOLUCION_EFECTIVO:
                $name = 'Devolucion_efectivo';
                break;
            case PaymentType::CHEQUE:
                $name = 'Cheque';
                break;
            case PaymentType::DESCUENTOS:
                $name = 'Descuentos';
                break;
            case PaymentType::QPASS_FALABELLA:
                $name = 'Qpass_falabella';
                break;
            case PaymentType::PLAZES:
                $name = 'Plazes';
                break;
            case PaymentType::VISA_PANAMA_US:
                $name = 'Visa_panama_us';
                break;
            case PaymentType::MASTERCARD_PANAMA_USD:
                $name = 'Mastercard_panama_usd';
                break;
            case PaymentType::SISTEMA_CLAVE_PANAMA_USD:
                $name = 'Sistema_clave_panama_usd';
                break;
            case PaymentType::QPASS_CAMPO_SANTO:
                $name = 'Qpass_campo_santo';
                break;
            case PaymentType::BONO_PEPE_GANGA:
                $name = 'Bono_pepe_ganga';
                break;
            case PaymentType::QPASS_POLUX:
                $name = 'Qpass_polux';
                break;
            case PaymentType::BONO_COOMEVA:
                $name = 'Bono_coomeva';
                break;
            case PaymentType::QPASS_ELECTRONICO:
                $name = 'Qpass_electronico';
                break;
            case PaymentType::NORMAL_QPASS:
                $name = 'Normal_qpass';
                break;
            case PaymentType::BONO_REDEBAN_50:
                $name = 'Bono_redeban_50';
                break;
            case PaymentType::BONO_REDEBAN_20:
                $name = 'Bono_redeban_20';
                break;
            case PaymentType::BONO_PROMOCION:
                $name = 'Bono_promocion';
                break;
            case PaymentType::CREDITO_RAPPI:
                $name = 'Credito_rappi';
                break;
            case PaymentType::BONO_DOMICILIO:
                $name = 'Bono_domicilio';
                break;
            case PaymentType::PAGOS_ONLINE:
                $name = 'PAGOS_ONLINE';
                break;
            case PaymentType::RAPICREDITO:
                $name = 'Rapicredito';
                break;
            case PaymentType::ONLINE_IFOOD:
                $name = 'Online_ifood';
                break;
            case PaymentType::TARJETAS_AUTO:
                $name = 'Tarjetas_auto';
                break;
            case PaymentType::BONO_QUANTUM:
                $name = 'Bono_quantum';
                break;
            case PaymentType::MUSIQ:
                $name = 'Musiq';
                break;
            case PaymentType::CREDITO_UBEREATS:
                $name = 'Credito_ubereats';
                break;
            case PaymentType::UBER_EATS:
                $name = 'Uber_eats';
                break;
            case PaymentType::FUERZAS_MILITARES:
                $name = 'Fuerzas_militares';
                break;
            case PaymentType::PROMOCION_BILLETE:
                $name = 'Promocion_billete';
                break;
            case PaymentType::CUPON_IFOOD:
                $name = 'Cupon_ifood';
                break;
            case PaymentType::WOMPI:
                $name = 'Wompi';
                break;
            case PaymentType::CLIENTES_CORPORATIVOS:
                $name = 'Clientes_corporativos';
            case PaymentType::MERCADO_PAGO:
                $name = 'Mercado_pago';
                break;
        }


        return $name;
    }

    public function getCreatedAtAttribute($value)
    {
        if ($this->order == null || $this->order->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->order->store)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        if ($this->order == null || $this->order->store == null) {
            return $value;
        }

        return TimezoneHelper::localizedDateForStore($value, $this->order->store)->toDateTimeString();
    }
}
