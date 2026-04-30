<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'dob',
        'current_address', 'native_address', 'aadhar', 'aadhar_text',
        'pan', 'marital_status', 'gender', 'phone', 'profile_image',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
