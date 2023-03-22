<?php

namespace App\Traits;

use App;
use App\Store;
use DateTime;
use DateTimeZone;
use App\Timezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

trait TimezoneHelper
{
    // Obtiene timezone del local; si no lo tiene configurado, lo obtiene del país.
    public static function getStoreTimezone(Store $store)
    {
        if (App::environment('testing')) {
            return TimezoneHelper::fetchStoreTimezone($store);
        }
        $timezone = Cache::get("store:{$store->id}:configs:timezone");
        if ($timezone === null || $timezone === '') {
            $timezone = TimezoneHelper::fetchStoreTimezone($store);
            Cache::forever("store:{$store->id}:configs:timezone", $timezone);
        }
        return $timezone;
    }

    public static function fetchStoreTimezone(Store $store)
    {
        $store->load('configs');
        $countryCode = $store->country_code;
        if (!$store->configs && !$countryCode) {
            return config('app.default_store_timezone');
        }
        $timezone = $store->configs->time_zone;
        if (!$timezone) {
            $timezone = isset(Timezone::TIMEZONE_BY_COUNTRY[$countryCode]) ?
            Timezone::TIMEZONE_BY_COUNTRY[$countryCode] : config('app.default_store_timezone');
        }
        return $timezone;
    }

    // Convierte fecha con timezone del local a server time (usar para búsquedas).
    public static function convertToServerDateTime($date, Store $store)
    {
        $storeDate = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $date,
            TimezoneHelper::getStoreTimezone($store)
        );
        return $storeDate->setTimezone(config('app.timezone'));
    }

    // Obtiene fecha y hora actual usando timezone de la tienda.
    public static function localizedNowDateForStore(Store $store)
    {
        return Carbon::now(TimezoneHelper::getStoreTimezone($store));
    }

    // Convierte fecha y hora al timezone de la tienda.
    public static function localizedDateForStore($date, Store $store)
    {
        return Carbon::parse($date)->setTimezone(TimezoneHelper::getStoreTimezone($store));
    }

    // Retorna el offset del timezone de la tienda
    public static function getStoreTimezoneOffset(Store $store)
    {
        $time = new DateTime('now', new DateTimeZone(TimezoneHelper::getStoreTimezone($store)));
        return $time->format('P');
    }

    // Retorna el offset del timezone del servidor
    public static function getServerTimezoneOffset()
    {
        $time = new DateTime('now', new DateTimeZone(config('app.timezone')));
        return $time->format('P');
    }
}
