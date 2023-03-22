<?php

namespace App\Traits;

use App;
use App\Store;
use DateTime;
use DateTimeZone;
use App\Timezone;
use Carbon\Carbon;

trait FormatterHelper
{

    public static function getNumberFormatByCountryCode($country, $value)
    {
        $countries = array();
        $countries['CO'] = number_format($value, 2, ".", ",");
        $countries['EC'] = number_format($value, 2, ".", ",");
        $countries['MX'] = number_format($value, 2, ",", ".");

        return $countries[$country];
    }

}