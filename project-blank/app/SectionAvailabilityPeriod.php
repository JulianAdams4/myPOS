<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SectionAvailabilityPeriod extends Model
{
    protected $fillable = [
        'section_availability_id',
        'start_time',
        'end_time',
        'start_day',
        'end_day'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function sectionAvailability()
    {
        return $this->belongsTo('App\SectionAvailability', 'section_availability_id');
    }
}
