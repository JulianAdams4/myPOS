<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    const MODULE = 'module';
    const ACTION = 'action';

    protected $fillable = ['role_id', 'identifier', 'type', 'label'];

    public function role()
    {
        return $this->belongsTo('App\Role');
    }

    public function users()
    {
        return $this->belongsToMany(
            'App\User',
            'user_permissions',
            'permission_id',
            'user_id'
        );
    }

    public function module()
    {
        return $this->belongsTo('App\Permission', 'module_id');
    }

    public function actions()
    {
        return $this->hasMany('App\Permission', 'module_id');
    }

    public function isModule()
    {
        return $this->type === Permission::MODULE;
    }

    public function isAction()
    {
        return $this->type === Permission::ACTION;
    }
}
