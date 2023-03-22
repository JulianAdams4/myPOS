<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SectionAvailability extends Model
{

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'section_id',
        'day',
        'enabled'
    ];

    public function section()
    {
        return $this->belongsTo('App\Section', 'section_id');
    }

    public function periods()
    {
        return $this->hasMany('App\SectionAvailabilityPeriod', 'section_availability_id');
    }
}
