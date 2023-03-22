<?php

namespace App;

use App\Helper;
use App\AvailableMyposIntegration;
use Illuminate\Database\Eloquent\Model;

class OrderIntegrationDetail extends Model
{
    protected $fillable = [
        'order_id',
        'integration_name',
        'external_order_id',
        'external_store_id',
        'external_customer_id',
        'external_created_at',
        'billing_id',
        'number_items',
        'value'
    ];

    public function integrationNameDescription()
    {
        switch ($this->integration_name) {
            case AvailableMyposIntegration::NAME_EATS:
                return "Uber Eats";
                break;
            case AvailableMyposIntegration::NAME_RAPPI:
                return "Rappi";
                break;
            case AvailableMyposIntegration::NAME_POSTMATES:
                return "Postmates";
                break;
            case AvailableMyposIntegration::NAME_SIN_DELANTAL:
                return "Sin Delantal";
                break;
            case AvailableMyposIntegration::NAME_DOMICILIOS:
                return "Domicilios.com";
                break;
            case AvailableMyposIntegration::NAME_IFOOD:
                return "iFood";
                break;
            case AvailableMyposIntegration::NAME_DIDI:
                return "Didi";
                break;
            case AvailableMyposIntegration::NAME_RAPPI_PAY:
                return "Rappi Pay";
                break;
            case AvailableMyposIntegration::NAME_MENIU:
                return "Meniu";
                break;
            case AvailableMyposIntegration::NAME_GROUPON:
                return "Groupon";
                break;
            case AvailableMyposIntegration::NAME_FINGER_FOOD:
                return "Finger Food";
                break;
            case AvailableMyposIntegration::NAME_WOMPI:
                return "Wompi";
                break;
            case AvailableMyposIntegration::NAME_CLIENTES_CORPORATIVOS:
                return "Clientes Corporativos";
                break;
            case AvailableMyposIntegration::NAME_RAPPI_ANTOJO:
                return "Rappi Antojo";
                break;
            case AvailableMyposIntegration::NAME_RAPPI_PICKUP:
                return "Rappi Pickup";
                break;
            case AvailableMyposIntegration::NAME_MERCADO_PAGO;
                return "Mercado Pago";
                break;
            case AvailableMyposIntegration::NAME_BONOS;
                return "Bonos Sodexo Bigpass";
                break;
            case AvailableMyposIntegration::NAME_EXITO;
                return "Exito";
                break;
            default:
                return "";
        }
    }

    public function order()
    {
        return $this->belongsTo('App\Order', 'order_id');
    }

    public function billing()
    {
        return $this->belongsTo('App\Billing', 'billing_id');
    }

}