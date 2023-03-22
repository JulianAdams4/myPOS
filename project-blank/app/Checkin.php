<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Checkin extends Model
{
  protected $fillable = [
    'type', 'employee_id', 'checkin', 'checkout'
  ];

  protected $dates = [
    'checkin',
    'checkout'
  ];

  public function employee()
  {
    return $this->belongsTo('App\Employee');
  }

  public function isEntry()
  {
    $type = $this->attributes['type'];
    return $type == CheckinType::ENTRY;
  }

  public function isExit()
  {
    $type = $this->attributes['type'];
    return $type == CheckinType::EXIT;
  }
}
