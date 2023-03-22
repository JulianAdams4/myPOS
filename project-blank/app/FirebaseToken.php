<?php

namespace App;

use App\Customer;
use Illuminate\Database\Eloquent\Model;

class FirebaseToken extends Model
{
    protected $table = 'firebase_tokens';

    protected $fillable = [
        'customer_id',
        'token',
        'platform'
    ];

    public function customer() {
        return $this->belongsTo('App\Customer', 'customer_id');
    }

    public static function addToken(Customer $customer, $token, $platform) {
        FirebaseToken::where('token', $token)->delete();
        $newToken = new FirebaseToken();
        $newToken->customer_id = $customer->id;
        $newToken->token = $token;
        $newToken->platform = $platform;
        $newToken->save();
    }
}
