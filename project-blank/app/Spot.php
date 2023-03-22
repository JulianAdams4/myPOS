<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use App\AvailableMyposIntegration;
use Illuminate\Database\Eloquent\SoftDeletes;

class Spot extends Model
{
    use SoftDeletes;

    // Constantes del origen de la mesa
    const ORIGIN_MYPOS_NORMAL = 0;
    const ORIGIN_MYPOS_DIVIDIR = 1;
    const ORIGIN_EATS = 2;
    const ORIGIN_RAPPI = 3;
    const ORIGIN_POSTMATES = 4;
    const ORIGIN_SIN_DELANTAL = 5;
    const ORIGIN_DOMICILIOS = 6;
    const ORIGIN_IFOOD = 7;
    const ORIGIN_RAPPI_ANTOJO = 8;
    const ORIGIN_RAPPI_PICKUP = 9;
    const ORIGIN_MYPOS_KIOSK = 10;
    const ORIGIN_MYPOS_KIOSK_TMP = 11;
    const ORIGIN_DIDI = 12;
    const ORIGIN_MENIU = 13;
    const ORIGIN_DELIVERY = 14;
    const ORIGIN_GROUPON = 15;
    const ORIGIN_LETS_EAT = 16;
    const ORIGIN_FINGER_FOOD = 17;
    const ORIGIN_DELIVERY_TMP = 18;
    const ORIGIN_MELY = 19;
    const ORIGIN_WOMPI = 20;
    const ORIGIN_CLIENTES_CORPORATIVOS = 21;
    const ORIGIN_MERCADO_PAGO = 22;
    const ORIGIN_BONOS = 23;
    const ORIGIN_EXITO = 24;

    protected $hidden = [
        'created_at', 'updated_at', 'deleted_at'
    ];

    protected $fillable = [
        'origin', 'name', 'store_id'
    ];

    public function store()
    {
        return $this->belongsTo('App\Store');
    }

    public function orders()
    {
        return $this->hasMany('App\Order');
    }

    public function isNormal()
    {
        return $this->origin == Spot::ORIGIN_MYPOS_NORMAL;
    }

    public function isSplit()
    {
        return $this->origin == Spot::ORIGIN_MYPOS_DIVIDIR;
    }

    public function isKiosk()
    {
        return $this->origin == Spot::ORIGIN_MYPOS_KIOSK;
    }

    public function isTmp()
    {
        return $this->origin == Spot::ORIGIN_MYPOS_KIOSK_TMP ||
            $this->origin == Spot::ORIGIN_DELIVERY_TMP;
    }

    public function isKioskTmp()
    {
        return $this->origin == Spot::ORIGIN_MYPOS_KIOSK_TMP;
    }

    public function isEats()
    {
        return $this->origin == Spot::ORIGIN_EATS;
    }

    public function isRappi()
    {
        return $this->origin == Spot::ORIGIN_RAPPI;
    }
    public function isMely()
    {
        return $this->origin == Spot::ORIGIN_MELY;
    }

    public function isWompi()
    {
        return $this->origin == Spot::ORIGIN_WOMPI;
    }

    public function isClientesCorporativos()
    {
        return $this->origin == Spot::ORIGIN_CLIENTES_CORPORATIVOS;
    }

    public function isRappiAntojo()
    {
        return $this->origin == Spot::ORIGIN_RAPPI_ANTOJO;
    }

    public function isRappiPickup()
    {
        return $this->origin == Spot::ORIGIN_RAPPI_PICKUP;
    }

    public function isPostmates()
    {
        return $this->origin == Spot::ORIGIN_POSTMATES;
    }

    public function isSinDelantal()
    {
        return $this->origin == Spot::ORIGIN_SIN_DELANTAL;
    }

    public function isDomicilios()
    {
        return $this->origin == Spot::ORIGIN_DOMICILIOS;
    }

    public function isIFood()
    {
        return $this->origin == Spot::ORIGIN_IFOOD;
    }

    public function isDidi()
    {
        return $this->origin == Spot::ORIGIN_DIDI;
    }

    public function isMeniu()
    {
        return $this->origin == Spot::ORIGIN_MENIU;
    }

    public function isDelivery()
    {
        return $this->origin == Spot::ORIGIN_DELIVERY;
    }

    public function isGroupon()
    {
        return $this->origin == Spot::ORIGIN_GROUPON;
    }

    public function isLetsEat()
    {
        return $this->origin == Spot::ORIGIN_LETS_EAT;
    }

    public function isFingerFood()
    {
        return $this->origin == Spot::ORIGIN_FINGER_FOOD;
    }

    public function isMercadoPago()
    {
        return $this->origin == Spot::ORIGIN_MERCADO_PAGO;
    }

    public function isBono()
    {
        return $this->origin == Spot::ORIGIN_BONOS;
    }

    public function isExito()
    {
        return $this->origin == Spot::ORIGIN_EXITO;
    }

    public function isFromIntegration()
    {
        return
            $this->isEats() ||
            $this->isRappi() ||
            $this->isRappiAntojo() ||
            $this->isRappiPickup() ||
            $this->isPostmates() ||
            $this->isSinDelantal() ||
            $this->isIFood() ||
            $this->isDidi() ||
            $this->isDomicilios() ||
            $this->isMely() ||
            $this->isWompi() ||
            $this->isClientesCorporativos();
            $this->isMercadoPago();
            $this->isBono();
            $this->isExito();
    }

    public static function getKioskSpot($storeId)
    {
        return Spot::where('origin', Spot::ORIGIN_MYPOS_KIOSK)
            ->where('store_id', $storeId)
            ->where('name', 'Kiosko')
            ->first();
    }

    public static function getDeliverySpot($storeId)
    {
        return Spot::firstOrCreate(
            [
                'origin' => Spot::ORIGIN_DELIVERY,
                'store_id' => $storeId
            ],
            ['name' => 'Delivery']
        );
    }

    public static function getSpotFromTmp($spot)
    {
        $spotName = $spot->origin == Spot::ORIGIN_MYPOS_KIOSK
            ? 'Kiosko'
            : 'Delivery';
        $spotOrigin = $spot->origin == Spot::ORIGIN_MYPOS_KIOSK_TMP
            ? Spot::ORIGIN_MYPOS_KIOSK
            : Spot::ORIGIN_DELIVERY;

        return Spot::firstOrCreate(
            [
                'origin' => $spotOrigin,
                'store_id' => $spot->store_id
            ],
            ['name' => $spotName]
        );
    }

    public static function getConstants()
    {
        $reflectionClass = new \ReflectionClass(static::class);
        return $reflectionClass->getConstants();
    }

    public function getNameIntegrationAttribute()
    {
        return Spot::getNameIntegrationByOrigin($this->origin);
    }

    public static function getNameIntegrationByOrigin($origin)
    {
        $nameIntegration = "";
        switch ($origin) {
            case Spot::ORIGIN_MYPOS_NORMAL:
                $nameIntegration = AvailableMyposIntegration::NAME_NORMAL;
                break;
            case Spot::ORIGIN_MYPOS_DIVIDIR:
                $nameIntegration = AvailableMyposIntegration::NAME_DIVIDIR;
                break;
            case Spot::ORIGIN_MYPOS_KIOSK:
                $nameIntegration = AvailableMyposIntegration::NAME_KIOSKO;
                break;
            case Spot::ORIGIN_EATS:
                $nameIntegration = AvailableMyposIntegration::NAME_EATS;
                break;
            case Spot::ORIGIN_RAPPI:
                $nameIntegration = AvailableMyposIntegration::NAME_RAPPI;
                break;
            case Spot::ORIGIN_RAPPI_ANTOJO:
                $nameIntegration = AvailableMyposIntegration::NAME_RAPPI_ANTOJO;
                break;
            case Spot::ORIGIN_RAPPI_PICKUP:
                $nameIntegration = AvailableMyposIntegration::NAME_RAPPI_PICKUP;
                break;
            case Spot::ORIGIN_POSTMATES:
                $nameIntegration = AvailableMyposIntegration::NAME_POSTMATES;
                break;
            case Spot::ORIGIN_SIN_DELANTAL:
                $nameIntegration = AvailableMyposIntegration::NAME_SIN_DELANTAL;
                break;
            case Spot::ORIGIN_DOMICILIOS:
                $nameIntegration = AvailableMyposIntegration::NAME_DOMICILIOS;
                break;
            case Spot::ORIGIN_IFOOD:
                $nameIntegration = AvailableMyposIntegration::NAME_IFOOD;
                break;
            case Spot::ORIGIN_DIDI:
                $nameIntegration = AvailableMyposIntegration::NAME_DIDI;
                break;
            case Spot::ORIGIN_MENIU:
                $nameIntegration = AvailableMyposIntegration::NAME_MENIU;
                break;
            case Spot::ORIGIN_DELIVERY:
                $nameIntegration = AvailableMyposIntegration::NAME_DELIVERY;
                break;
            case Spot::ORIGIN_GROUPON:
                $nameIntegration = AvailableMyposIntegration::NAME_GROUPON;
                break;
            case Spot::ORIGIN_LETS_EAT:
                $nameIntegration = AvailableMyposIntegration::NAME_LETS_EAT;
                break;
            case Spot::ORIGIN_FINGER_FOOD:
                $nameIntegration = AvailableMyposIntegration::NAME_FINGER_FOOD;
                break;
            case Spot::ORIGIN_MELY:
                $nameIntegration = AvailableMyposIntegration::NAME_MELY;
                break;
            case Spot::ORIGIN_WOMPI:
                $nameIntegration = AvailableMyposIntegration::NAME_WOMPI;
                break;
            case Spot::ORIGIN_CLIENTES_CORPORATIVOS:
                $nameIntegration = AvailableMyposIntegration::NAME_CLIENTES_CORPORATIVOS;
                break;
            case Spot::ORIGIN_MERCADO_PAGO:
                $nameIntegration = AvailableMyposIntegration::NAME_MERCADO_PAGO;
                break; 
            case Spot::ORIGIN_BONOS:
                $nameIntegration = AvailableMyposIntegration::NAME_BONOS;
                break;
            case Spot::ORIGIN_EXITO:
                $nameIntegration = AvailableMyposIntegration::NAME_EXITO;
                break;
        }
        return $nameIntegration;
    }
}
