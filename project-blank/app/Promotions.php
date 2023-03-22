<?php

namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Promotions extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'promotion_type_id',
        'discount_type_id',
        'is_entire_menu',
        'requiered_recipe',
        'is_unlimited',
        'condition_value',
        'max_apply',
        'times_applied',
        'from_date',
        'to_date',
        'from_time',
        'to_time',
        'status'
    ];
    protected $appends = [
        'duration','schedule','creation_date'
    ];
    public function getDurationAttribute()
    {
        $meses = array("Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre");
        $desde= Carbon::parse($this->from_date);
        $hasta= Carbon::parse($this->to_date); 
        
        $mes_desde=$meses[($desde->format('n')) - 1];
        $mes_hasta=$meses[($hasta->format('n')) - 1];
        $dia_desde= $desde->day;
        $dia_hasta=$hasta->day;
        
        return  $mes_desde.' '.$dia_desde.' - '.$mes_hasta.' '.$dia_hasta;
    }
    public function getScheduleAttribute()
    {
        $hora_desde= explode(':',$this->from_time);
        $hora_hasta= explode(':',$this->to_time);
        $hora_desde_mostar= $hora_desde[0].':'.$hora_desde[1];
        $hora_hasta_mostar= $hora_hasta[0].':'.$hora_hasta[1];
        if($hora_desde_mostar=='00:00' && $hora_hasta_mostar=='23:59'){
            return 'Todo el día';
        }else{
            return $hora_desde_mostar.' '.$hora_hasta_mostar;
        }   
    }
    public function getCreationDateAttribute()
    {
        $meses = array("Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre");
        $creacion= Carbon::parse($this->created_at);
        $mes_creacion=$meses[($creacion->format('n')) - 1];
        $anio_creacion=$creacion->year;
        $dia_creacion=$creacion->day;
        $hora_creacion=$creacion->hour;
        $minuto_creacion=$creacion->minute; 
        return $mes_creacion.' '.$dia_creacion.', '.$anio_creacion. ' '.$hora_creacion.':'.$minuto_creacion;
    }
    public function promotion_stores()
    {
        return $this->hasMany('App\StorePromotion', 'promotion_id','id');
    }
    public function promotion_type()
    {
        return $this->hasOne('App\PromotionTypes', 'id','promotion_type_id');
    }
}
