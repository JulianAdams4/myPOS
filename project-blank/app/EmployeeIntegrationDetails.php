<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class EmployeeIntegrationDetails extends Model
{
    public $fillable = ['employee_id', 'integration_name', 'integration_type', 'external_id'];
}
