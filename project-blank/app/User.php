<?php

namespace App;

use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password', 'active', 'email_verified_at', 'ci'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'activation_token', 'created_at', 'updated_at', 'api_token',
    ];

    public function hub()
    {
        return $this->hasOne('App\Hub');
    }

    public function role()
    {
        return $this->belongsTo('App\Role');
    }

    public function permissions()
    {
        return $this->belongsToMany(
            'App\Permission',
            'user_permissions',
            'user_id',
            'permission_id'
        );
    }

    public function modules()
    {
        return $this->belongsToMany(
            'App\Permission',
            'user_permissions',
            'user_id',
            'permission_id'
        )->where('type', Permission::MODULE);
    }

    public function actions()
    {
        return $this->belongsToMany(
            'App\Permission',
            'user_permissions',
            'user_id',
            'permission_id'
        )->where('type', Permission::ACTION);
    }

    public function customers()
    {
        return $this->hasMany('App\Customer');
    }

    public function isAdmin()
    {
        return $this->role->name === Role::ADMIN;
    }

    public function isAdminStore()
    {
        return $this->role->name === Role::ADMIN_STORE;
    }

    public function isEmployee()
    {
        return $this->role->name === Role::EMPLOYEE;
    }

    public function employees()
    {
        return $this->hasMany('App\Employee');
    }

    public function isEmployeePlaza()
    {
        return $this->role->name === Role::PLAZA;
    }

    public function isAdminFranchise()
    {
        return $this->role->name === Role::ADMIN_FRANCHISE;
    }
}
