<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    const ADMIN       = 'admin';
    const ADMIN_STORE = 'admin_store';
    const EMPLOYEE    = 'employee';
    const PLAZA       = 'plaza';
    const ADMIN_FRANCHISE = 'admin_franchise';

    protected $fillable = ['name'];

    public function users()
    {
        return $this->hasMany('App\User');
    }

    public function permissions()
    {
        return $this->hasMany('App\Permission');
    }

    public function modules()
    {
        return $this->hasMany('App\Permission')->where('type', Permission::MODULE);
    }

    public function actions()
    {
        return $this->hasMany('App\Permission')->where('type', Permission::ACTION);
    }
}
