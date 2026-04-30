<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormConfig extends Model
{
    protected $fillable = ['form', 'fields'];

    protected $casts = [
        'fields' => 'array',
    ];
}
