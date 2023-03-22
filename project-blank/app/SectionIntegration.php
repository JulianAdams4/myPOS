<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SectionIntegration extends Model
{
    
    protected $casts = [
        'status_sync' => 'array'
    ];

    protected $fillable = [
        'section_id',
        'integration_id'
    ];
    public function section()
    {
        return $this->belongsTo('App\Section', 'section_id');
    }

    public function integration()
    {
        return $this->belongsTo('App\AvailableMyposIntegration', 'integration_id');
    }
}
