<?php

namespace App\Traits;

use Carbon\Carbon;
use Log;

trait Utility
{
    public function dayOfWeek($int)
    {
        if ($int === 1) return 'monday';
        if ($int === 2) return 'tuesday';
        if ($int === 3) return 'wednesday';
        if ($int === 4) return 'thursday';
        if ($int === 5) return 'friday';
        if ($int === 6) return 'saturday';
        if ($int === 7) return 'sunday';
        return "";
    }

    public function dayOfWeekES($int)
    {
        if ($int === 1) return 'Lunes';
        if ($int === 2) return 'Martes';
        if ($int === 3) return 'Miércoles';
        if ($int === 4) return 'Jueves';
        if ($int === 5) return 'Viernes';
        if ($int === 6) return 'Sábado';
        if ($int === 7) return 'Domingo';
        return "";
    }
    static public function staticDayOfWeekES($int)
    {
        if ($int === 1) return 'Lunes';
        if ($int === 2) return 'Martes';
        if ($int === 3) return 'Miércoles';
        if ($int === 4) return 'Jueves';
        if ($int === 5) return 'Viernes';
        if ($int === 6) return 'Sábado';
        if ($int === 7) return 'Domingo';
        return "";
    }
    static public function staticDayOfWeek($int)
    {
        if ($int === 1) return 'monday';
        if ($int === 2) return 'tuesday';
        if ($int === 3) return 'wednesday';
        if ($int === 4) return 'thursday';
        if ($int === 5) return 'friday';
        if ($int === 6) return 'saturday';
        if ($int === 7) return 'sunday';
        return "";
    }
}
